# v3.1.1
## 05/10/2023

1. [](#improved)
   * Fixed a PHP 8.2+ deprecation issue

# v3.1.0
## 12/12/2022

1. [](#new)
   * Using blocks in `partials/langswitcher.html.twig` to make it easier to extend without having to copy the logic

# v3.0.2
## 10/05/2022

1. [](#new)
   * Require Grav `1.7.37` to make use of the new `Pages::getSimplePagesHash()` method
   * Added caching to `translated_routes` so translation work is only performed on the first load, resulting in faster subsequent page loads

# v3.0.1
## 08/19/2022

1. [](#bugfix)
   * Fixed another issue with incorrect `hreflang` URLs

# v3.0.0
## 08/19/2022

1. [](#new)
   * Completely rewrote the logic for translated URLs to be more robust.
   * Added configuration option to use **Translated URLs** or use previous **Raw-Route** approach
1. [](#improved)
   * Updated `hreflang` Twig template to use new translated URLs logic
   * Added an `x-default` entry for `hreflang` template when default language has `include_default_lang` set to false
   * Support `params` and `query` string parameters in URLs
   * Full domain URLs for `hreflang` entries
     
# v2.0.1
## 08/04/2022

1. [](#bugfix)
   * Fixed exception thrown instead of **404 Page not found** [#66](https://github.com/getgrav/grav-plugin-langswitcher/issues/66)

# v2.0.0
## 07/25/2022

1. [](#new)
    * Support for translated slugs!!!! [#50](https://github.com/getgrav/grav-plugin-langswitcher/pull/50)
    * Require Grav `1.7`
1. [](#improved)
    * Improved support for home URL [#59](https://github.com/getgrav/grav-plugin-langswitcher/pull/59)   

# v1.5.0
## 07/01/2021

1. [](#new)
   * Made langswitcher display more customizable.  See README.md for full details.

# v1.4.3
## 06/25/2021

1. [](#new)
   * Made langswitcher data available in Grav object
1. [](#bugfix)
   * Fix multilang alternatives [#58](https://github.com/getgrav/grav-plugin-langswitcher/pull/58)
# v1.4.2
## 03/17/2021

1. [](#new)
    * Pass phpstan level 1 tests
    * Require Grav v1.6
1. [](#bugfix)
    * Fix `hreflang` URLs [#57](https://github.com/getgrav/grav-plugin-langswitcher/pull/57)

# v1.4.1
## 05/09/2019

1. [](#new)
    * Added some translations [#45](https://github.com/getgrav/grav-plugin-langswitcher/pull/45)

# v1.4.0
## 06/29/2017

1. [](#new)
    * Added the `untranslated_pages_behavior` option to determine what to do with a language link when the current page doesn't exist in that language or it exists but it's not published
1. [](#bugfix)
    * Fixed generated URLs when `append_url_extension` is set, via PR [#22](https://github.com/getgrav/grav-plugin-langswitcher/pull/22)

# v1.3.0
## 02/17/2017

1. [](#new)
    * Added support for `hreflang` annotations via PR [#19](https://github.com/getgrav/grav-plugin-langswitcher/pull/19)

# v1.2.1
## 05/28/2016

1. [](#bugfix)
    * Display all language names, even those with non supported locales

# v1.2.0
## 05/03/2016

1. [](#improved)
    * Take URI parameters into account when switching languages
    * Add `external` class to avoid problems on modular pages when `jquery.singlePageNav` is loaded

# v1.1.0
## 10/15/2015

1. [](#improved)
    * Added active class to language links

# v1.0.2
## 07/13/2015

1. [](#improved)
    * Improved homepage routing

# v1.0.1
## 07/08/2015

1. [](#improved)
    * Updated blueprints with some typo fixes

# v1.0.0
## 07/08/2015

1. [](#new)
    * ChangeLog started...
