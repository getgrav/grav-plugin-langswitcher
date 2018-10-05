<?php
namespace Grav\Plugin;

use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Page;
use \Grav\Common\Plugin;

class LangSwitcherPlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize configuration
     */
    public function onPluginsInitialized()
    {
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }

        $this->enable([
            'onTwigInitialized'   => ['onTwigInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigSiteVariables' => ['onTwigSiteVariables', 0]
        ]);
    }

    /** Add the native_name function */
    public function onTwigInitialized()
    {
        $this->grav['twig']->twig()->addFunction(
            new \Twig_SimpleFunction('native_name', function($key) {
                return LanguageCodes::getNativeName($key);
            })
        );
    }

    /**
     * Add current directory to twig lookup paths.
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }


    /**
     * Generalized method to get a page object for a given language
     */
    protected function getTranslatedPageItem($page, $language) {
        $page_name_without_ext = substr($page->name(), 0, -(strlen($page->extension())));
        $translated_page_path = $page->path() . DS . $page_name_without_ext . '.' . $language . '.md';
        if (file_exists($translated_page_path)) {
            $translated_page = new Page();
            $translated_page->init(new \SplFileInfo($translated_page_path), $language . '.md');
            return $translated_page;
        }
        return null;
    }
    
    /**
     * Get the transalated slug for a given page
     */
    protected function getTranslatedSlugOfPage($page, $language) {
        $translatedPage = $this->getTranslatedPageItem($page, $language);

        if ($translatedPage) {
            return $translatedPage->slug();
        } else {
            return null;
        }
    }

    /**
     * Recursive function to get the translated path of a page by going through its parents
     */
    public function getTranslatedFullPagePath($page, $language, $maxDepth=100, $depth = 0){
        if ($maxDepth && $depth > $maxDepth) {
            return null;
        }

        $slug = $this->getTranslatedSlugOfPage($page, $language);

        $parent = $page->parent();
        if ($parent) {
            $parentSlug = $this->getTranslatedFullPagePath($parent, $language, $maxDepth, $depth+1);
            if ($parentSlug) {
                return $parentSlug . "/" . $slug;
            } else {
                return $slug;
            }
        } else {
            return $slug;
        }
    }



    /**
     * Set needed variables to display Langswitcher.
     */
    public function onTwigSiteVariables()
    {
        $data = new \stdClass;

        $page = $this->grav['page'];
        $data->page_route = $page->rawRoute();
        if ($page->home()) {
            $data->page_route = '/';
        }

        $data->translated_page_routes = [];

        $languages = $this->grav['language']->getLanguages();
        $data->languages = $languages;

        if ($this->config->get('plugins.langswitcher.untranslated_pages_behavior') !== 'none') {
            $translated_pages = [];
            foreach ($languages as $language) {
                $translated_pages[$language] = null;

                $translatedPage = $this->getTranslatedPageItem($page, $language);
                if ($translatedPage) {
                    $translated_pages[$language] = $translatedPage;

                    $data->translated_page_routes[$language] = $this->getTranslatedFullPagePath($page, $language);
                }
            }
            $data->translated_pages = $translated_pages;
        }

        $data->current = $this->grav['language']->getLanguage();

        $this->grav['twig']->twig_vars['langswitcher'] = $data;

        if ($this->config->get('plugins.langswitcher.built_in_css')) {
            $this->grav['assets']->add('plugin://langswitcher/css/langswitcher.css');
        }
    }

    public function getNativeName($code) {

    }
}
