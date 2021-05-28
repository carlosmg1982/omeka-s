<?php declare(strict_types=1);
/**
 * Created by IntelliJ IDEA.
 * User: xavier
 * Date: 17/05/18
 * Time: 16:01
 */
namespace IiifSearch\View\Helper;

use IiifSearch\Iiif\AnnotationList;
use IiifSearch\Iiif\AnnotationSearchResult;
use IiifSearch\Iiif\SearchHit;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Representation\ItemRepresentation;
use SimpleXMLElement;

class IiifSearch extends AbstractHelper
{
    /**
     * @var array
     */
    protected $xmlSupportedMediaTypes = [
        'application/vnd.alto+xml',
    ];

    /**
     * @var int
     */
    protected $minimumQueryLength = 3;

    protected $xmlMediaTypes = [
        'application/xml',
        'text/xml'
    ];

    /**
     * Full path to the files.
     *
     * @var string
     */
    protected $basePath;

    /**
     * @var ItemRepresentation
     */
    protected $item;

    /**
     * @var array
     */
    protected $pages;

    public function __construct($basePath)
    {
        $this->basePath = $basePath;
    }

    /**
     * Get the IIIF search response for fulltext research query.
     *
     * @param ItemRepresentation $item
     * @return AnnotationList|null Null is returned if search is not supported
     * for the resource.
     */
    public function __invoke(ItemRepresentation $item)
    {
        $this->item = $item;

        if (!$this->prepareSearch()) {
            return null;
        }

        $query = (string) $this->getView()->params()->fromQuery('q');
        $result = $this->searchFulltext($query);

        $response = new AnnotationList;
        $response->initOptions(['requestUri' => $this->getView()->serverUrl(true)]);
        if ($result) {
            $response['resources'] = $result['resources'];
            $response['hits'] = $result['hits'];
        }
        $response->isValid(true);
        return $response;
    }

    /**
     * Returns answers to a query.
     *
     * @todo add xml validation ( pdf filename == xml filename according to Extract Ocr plugin )
     *
     * @return array|null
     *  Return resources that match query for IIIF Search API
     * [
     *      [
     *          '@id' => 'https://your_domain.com/omeka-s/iiif-search/itemID/searchResults/ . a . numCanvas . h . numresult. r .  xCoord , yCoord, wCoord , hCoord ',
     *          '@type' => 'oa:Annotation',
     *          'motivation' => 'sc:painting',
     *          [
     *              '@type' => 'cnt:ContentAsText',
     *              'chars' =>  corresponding match char list ,
     *          ]
     *          'on' => canvas url with coordonate for IIIF Server module,
     *      ]
     *      ...
     */
    protected function searchFulltext(string $query)
    {
        if (!strlen($query)) {
            return null;
        }

        $queryWords = $this->formatQuery($query);
        if (empty($queryWords)) {
            return null;
        }

        $this->prepareImageSizes();

        return $this->searchFullTextXml($queryWords);
    }

    protected function searchFullTextXml($queryWords)
    {
        $result = [
            'resources' => [],
            'hits' => [],
        ];

        // A search result is an annotation on the canvas of the original item,
        // so an url managed by the iiif server.
        $view = $this->getView();
        $baseResultUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'annotation',
            'name' => 'search-result',
        ]) . '/';

        $baseCanvasUrl = $view->iiifUrl($this->item, 'iiifserver/uri', null, [
            'type' => 'canvas',
        ]) . '/p';

        $resource = $this->item;
        $matches = [];
        try {
            $hit = 0;
            $index = 0;
            foreach($this->pages as $key=>$item) {
                if(isset($item['xml'])) {
                    $pageIndex = $key+1;
                    $xml = $this->loadXml($item['xml']);
                    foreach ($xml->Layout->Page as $xmlPage) {
                        ++$index;
                        $attributes = $xmlPage->attributes();
                        //$page['number'] = (string) @$attributes->PHYSICAL_IMG_NR;
                        $page['number'] = (string) $pageIndex;
                        $page['width'] = (string)@$attributes->WIDTH;
                        $page['height'] = (string)@$attributes->HEIGHT;
                        if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                            $view->logger()->warn(sprintf(
                                'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                                $item['xml']->id(), $index
                            ));
                            continue;
                        }

                        $hits = [];
                        $hitMatches = [];
                        $rowIndex = -1;
                        foreach ($xmlPage->PrintSpace->ComposedBlock as $indexComposedBlock => $xmlComposedBlock) {
                            foreach ($xmlComposedBlock->TextBlock as $indexTextBlock => $xmlTextBlock) {
                                foreach ($xmlTextBlock->TextLine as $indexTextLine => $xmlTextLine) {
                                    foreach ($xmlTextLine as $xmlWord) {
                                        ++$rowIndex;

                                        $wordAttributes = $xmlWord->attributes();

                                        $zone = [];
                                        $zone['text'] = strip_tags((string)@$wordAttributes->CONTENT);
                                        foreach ($queryWords as $chars) {
                                            if (!empty($item['width'])
                                                && !empty($item['height'])
                                                && mb_strlen($chars) >= $this->minimumQueryLength
                                                && preg_match('/' . preg_quote($chars, '/') . '/Uui', $zone['text'], $matches) > 0
                                            ) {
                                                $zone['top'] = (string)@$wordAttributes->VPOS;
                                                $zone['left'] = (string)@$wordAttributes->HPOS;
                                                $zone['width'] = (string)@$wordAttributes->WIDTH;
                                                $zone['height'] = (string)@$wordAttributes->HEIGHT;
                                                if (!strlen($zone['top']) || !strlen($zone['left']) || !strlen($zone['width']) || !strlen($zone['height'])) {
                                                    $view->logger()->warn(sprintf(
                                                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                                                        $item['xml']->id(), $pageIndex
                                                    ));
                                                    continue;
                                                }

                                                ++$hit;

                                                $image = ['width'=>$item['width'],'height'=>$item['height'],'media'=>$item['media']];

                                                $searchResult = new AnnotationSearchResult;
                                                $searchResult->initOptions(['baseResultUrl' => $baseResultUrl, 'baseCanvasUrl' => $baseCanvasUrl]);
                                                $result['resources'][] = $searchResult->setResult(compact('resource', 'image', 'page', 'zone', 'chars', 'hit'));

                                                $hits[] = $searchResult->getId();
                                                // TODO Get matches as whole world and all matches in last time (preg_match_all).
                                                // TODO Get the text before first and last hit of the page.
                                                $hitMatches[] = $matches[0];
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        // Add hits per page.
                        if ($hits) {
                            foreach($hits as $hit) {
                                $searchHit = new SearchHit;
                                $searchHit['annotations'] = $hits;
                                $searchHit['match'] = implode(' ', array_unique($hitMatches));
                                $result['hits'][] = $searchHit;
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $view->logger()->err(sprintf('Error: PDF to XML conversion failed for media file #%d!'));
            return null;
        }

        return $result;
    }

    /**
     * Check if the item support search and init the xml file.
     *
     * @return bool
     */
    protected function prepareSearch()
    {

        $view = $this->getView();
        $this->pages = [];
        $images = [];
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                $this->pages[]['media'] = $media;
                $images[] = $media->id();
            }
        }
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if (
                    in_array($mediaType, $this->xmlMediaTypes) &&
                    $media->value('dcterms:isFormatOf') !== null &&
                    in_array($media->value('dcterms:isFormatOf')->valueResource()->id(),$images)
            ) {
                $this->pages[array_search($media->value('dcterms:isFormatOf')->valueResource()->id(),$images)]['xml'] = $media;
            }
        }
        return count($this->pages);
    }

    protected function prepareImageSizes(): void
    {
        // TODO Use plugin imageSize from modules IiifServer and ImageServer.
        foreach ($this->pages as &$image) {
            // Some media types don't save the file locally.
            if ($filename = $image['media']->filename()) {
                $filepath = $this->basePath . '/original/' . $filename;
            } else {
                $filepath = $image['media']->originalUrl();
            }
            list($image['width'], $image['height']) = getimagesize($filepath);
        }
    }

    /**
     * Normalize query because the search occurs inside a normalized text.
     * @param $query
     * @return array
     */
    protected function formatQuery($query)
    {
        $cleanQuery = $this->alnumString($query);
        if (mb_strlen($cleanQuery) < $this->minimumQueryLength) {
            return [];
        }

        $queryWords = explode(' ', $cleanQuery);
        if (count($queryWords) > 1) {
            $queryWords[] = $cleanQuery;
        }

        return $queryWords;
    }

    /**
     * @return \SimpleXMLElement|null
     */
    protected function loadXml($resource)
    {
        $filepath = ($filename = $resource->filename())
            ? $this->basePath . '/original/' . $filename
            : $resource->originalUrl();

        $mediaType = $this->getView()->xmlMediaType($filepath, $resource->mediaType());
        if (!in_array($mediaType, $this->xmlSupportedMediaTypes)) {
            $this->getView()->logger()->err(sprintf('Error: Xml format "%1$s" is not managed currently (media #%2$d).', $mediaType, $resource->id()));
            return null;
        }

        $xmlContent = simplexml_load_file($filepath);

        if (!$xmlContent) {
            $this->getView()->logger()->err(sprintf('Error: Cannot get XML content from media #%d!', $resource->id()));
            return null;
        }

        return $xmlContent;
    }

    /**
     * Returns a cleaned  string.
     *
     * Removes trailing spaces and anything else, except letters, numbers and
     * symbols.
     *
     * @param string $string The string to clean.
     * @return string The cleaned string.
     */
    protected function alnumString($string)
    {
        $string = preg_replace('/[^\p{L}\p{N}\p{S}]/u', ' ', $string);
        return trim(preg_replace('/\s+/', ' ', $string));
    }
}
