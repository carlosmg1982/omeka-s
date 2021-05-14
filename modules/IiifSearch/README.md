IIIF Search (module for Omeka S)
=============================

Summary
-----------

IIIF Search is a module for Omeka S that add IIIF Search Api for fulltext searching.

Require Modules
---------------

- This module needs [Extract OCR](https://github.com/bubdxm/Omeka-S-module-ExtractOcr) and [IIIF-Server](https://github.com/bubdxm/Omeka-S-module-IiifServer) modules on your server

Installation
------------
- install the module via github

```sh
cd omeka-s/modules
git clone git@github.com:bubdxm/Omeka-S-module-IiifSearch.git "IiifSearch"
```

- Install it from the admin → Modules → IiifSearch -> install

- In IIIF Server's settings -> IIIF Search url 
  add iiif-search url : http://yourdomain/omeka-s/iiif-search/

Using the Iiif Search module
---------------------------

You can use API with :

http://yourdomain/omeka-s/iiif-search/:itemID?q=textquery   

Iiif Search module will return Iiif Search response.

Optional modules
----------------

- [Universal Viewer](https://gitlab.com/Daniel-KM/Omeka-S-module-UniversalViewer) : Module for Omeka S that adds the IIIF specifications in order to act like an IIPImage server, and the UniversalViewer, a unified online player for any file. It can display books, images, maps, audio, movies, pdf, 3D views, and anything else as long as the appropriate extensions are installed.

Troubleshooting
---------------

See online [IIIF Search issues](https://github.com/bubdxm/Omeka-S-module-IiifSearch/issues).

License
-------

This module is published under [GNU/GPL](https://www.gnu.org/licenses/gpl-3.0.html).

This program is free software; you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation; either version 3 of the License, or (at your option) any later
version.

This program is distributed in the hope that it will be useful, but WITHOUT
ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
details.

You should have received a copy of the GNU General Public License along with
this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.


Contact
-------

* Syvain Machefert, Université Bordeaux 3 (see [symac](https://github.com/symac))

