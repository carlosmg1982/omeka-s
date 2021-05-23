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
     * @var \Omeka\Api\Representation\MediaRepresentation
     */
    protected $xmlFile;

    /**
     * @var string
     */
    protected $xmlMediaType;

    /**
     * @var array
     */
    protected $imageSizes;

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

        $xml = $this->loadXml();
        if (empty($xml)) {
            return null;
        }

        $this->prepareImageSizes();

        $view = $this->getView();
        $view->logger()->warn(print_r($this->searchFullTextPdfXml($xml, $queryWords),true));
        return $this->searchFullTextPdfXml($xml, $queryWords);
    }

    protected function searchFullTextPdfXml(SimpleXmlElement $xml, $queryWords)
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

        //$view->logger()->warn(print_r($this->item,true));
        //$view->logger()->warn(print_r($this->imageSizes));

        $resource = $this->item;
        $matches = [];
        try {
            $hit = 0;
            $index = 0;
            foreach($xml->Layout->Page as $xmlPage) {
                ++$index;
                $attributes = $xmlPage->attributes();
                $page['number'] = (string) @$attributes->PHYSICAL_IMG_NR;
                $page['width'] = (string) @$attributes->WIDTH;
                $page['height'] = (string) @$attributes->HEIGHT;
                if (!strlen($page['number']) || !strlen($page['width']) || !strlen($page['height'])) {
                    $view->logger()->warn(sprintf(
                        'Incomplete data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }

                $pageIndex = 0;
                // Should be the same than index.
                //$pageIndex = $page['number'] - 1;
                /*
                $pageIndex = $page['number'];
                $view->logger()->warn($pageIndex." ".$index);
                if ($pageIndex !== $index) {
                    $view->logger()->warn(sprintf(
                        'Inconsistent data for xml file from pdf media #%1$s, page %2$s.', // @translate
                        $this->xmlFile->id(), $index
                    ));
                    continue;
                }
                */

                $hits = [];
                $hitMatches = [];
                $rowIndex = -1;
                foreach ($xmlPage->PrintSpace->ComposedBlock as $indexComposedBlock => $xmlComposedBlock) {
                    foreach($xmlComposedBlock->TextBlock as $indexTextBlock => $xmlTextBlock) {
                        foreach($xmlTextBlock->TextLine as $indexTextLine => $xmlTextLine) {
                            foreach($xmlTextLine as $xmlWord) {
                                ++$rowIndex;

                                $wordAttributes = $xmlWord->attributes();

                                $zone = [];
                                $zone['text'] = strip_tags((string) @$wordAttributes->CONTENT);
                                foreach ($queryWords as $chars) {
                                    if (!empty($this->imageSizes[$pageIndex]['width'])
                                        && !empty($this->imageSizes[$pageIndex]['height'])
                                        && mb_strlen($chars) >= $this->minimumQueryLength
                                        && preg_match('/' . preg_quote($chars, '/') . '/Uui', $zone['text'], $matches) > 0
                                    ) {
                                        $zone['top'] = (string) @$wordAttributes->VPOS;
                                        $zone['left'] = (string) @$wordAttributes->HPOS;
                                        $zone['width'] = (string) @$wordAttributes->WIDTH;
                                        $zone['height'] = (string) @$wordAttributes->HEIGHT;
                                        if (!strlen($zone['top']) || !strlen($zone['left']) || !strlen($zone['width']) || !strlen($zone['height'])) {
                                            $view->logger()->warn(sprintf(
                                                'Inconsistent data for xml file from pdf media #%1$s, page %2$s, row %3$s.', // @translate
                                                $this->xmlFile->id(), $pageIndex, $rowIndex
                                            ));
                                            continue;
                                        }

                                        $view->logger()->warn(print_r($zone,true));

                                        ++$hit;

                                        $view->logger()->warn(print_r($hit,true));
                                        $view->logger()->warn(print_r($page,true));
                                        $view->logger()->warn(print_r($chars,true));
                                        $view->logger()->warn(print_r($resource,true));

                                        $image = $this->imageSizes[$pageIndex];

                                        $view->logger()->warn(print_r($image,true));
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
                    $searchHit = new SearchHit;
                    $searchHit['annotations'] = $hits;
                    $searchHit['match'] = implode(' ', array_unique($hitMatches));
                    $result['hits'][] = $searchHit;
                }
            }
        } catch (\Exception $e) {
            $view->logger()->err(sprintf('Error: PDF to XML conversion failed for media file #%d!', $this->xmlFile->id()));
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
        $this->xmlFile = null;
        $this->imageSizes = [];
        $view = $this->getView();
        $view->logger()->warn(print_r($this->imageSizes,true));
        foreach ($this->item->media() as $media) {
            $mediaType = $media->mediaType();
            if (!$this->xmlFile && in_array($mediaType, $this->xmlMediaTypes)) {
                $this->xmlFile = $media;
                $view->logger()->warn(__LINE__);
                $view->logger()->warn(print_r($media->displayTitle(),true));
            } elseif ($media->hasOriginal() && strtok($mediaType, '/') === 'image') {
                $this->imageSizes[] = ['filename'=>$media->filename(),'originalUrl'=>$media->originalUrl()];
                $view->logger()->warn(__LINE__);
                $view->logger()->warn(print_r(['filename'=>$media->filename(),'originalUrl'=>$media->originalUrl()],true));
            }
        }
        return isset($this->xmlFile) && count($this->imageSizes);
    }

    protected function prepareImageSizes(): void
    {
        $view = $this->getView();
        $view->logger()->warn(__LINE__);
        $view->logger()->warn(print_r($this->imageSizes,true));
        // TODO Use plugin imageSize from modules IiifServer and ImageServer.
        foreach ($this->imageSizes as &$image) {
            // Some media types don't save the file locally.
            if ($filename = $image['filename']) {
                $filepath = $this->basePath . '/original/' . $filename;
            } else {
                $filepath = $image['originalUrl'];
            }
            list($image['width'],$image['height']) = getimagesize($filepath);
        }
        $view->logger()->warn(print_r($this->imageSizes,true));
        $view->logger()->warn(__LINE__);
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
    protected function loadXml()
    {
        $filepath = ($filename = $this->xmlFile->filename())
            ? $this->basePath . '/original/' . $filename
            : $this->xmlFile->originalUrl();

        $this->xmlMediaType = $this->getView()->xmlMediaType($filepath, $this->xmlFile->mediaType());
        if (!in_array($this->xmlMediaType, $this->xmlSupportedMediaTypes)) {
            $this->getView()->logger()->err(sprintf('Error: Xml format "%1$s" is not managed currently (media #%2$d).', $this->xmlMediaType, $this->xmlFile->id()));
            return null;
        }

        $xmlContent = simplexml_load_file($filepath);

        if (!$xmlContent) {
            $this->getView()->logger()->err(sprintf('Error: Cannot get XML content from media #%d!', $this->xmlFile->id()));
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
