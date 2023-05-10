<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Cache;
use Grav\Common\Language\Language;
use Grav\Common\Language\LanguageCodes;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use \Grav\Common\Plugin;

class LangSwitcherPlugin extends Plugin
{

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0]
            ]
        ];
    }

    /**
     * [onPluginsInitialized:100000] Composer autoload.
     *
     * @return ClassLoader
     */
    public function autoload()
    {
        return require __DIR__ . '/vendor/autoload.php';
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
     * Generate localized route based on the translated slugs found through the pages hierarchy
     */
    protected function getTranslatedUrl($lang, $path)
    {
        /** @var Language $language */
        $url = null;
        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        /** @var Language $language */
        $language = $this->grav['language'];

        $language->init();
        $language->setActive($lang);
        $pages->reset();
        $page = $pages->get($path);
        if ($page) {
            $url = $page->url();
        }
        return $url;
    }

    /**
     * Set needed variables to display Langswitcher.
     */
    public function onTwigSiteVariables()
    {

        /** @var PageInterface $page */
        $page = $this->grav['page'];

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        /** @var Cache $cache */
        $cache = $this->grav['cache'];

        $data = new \stdClass;
        $data->page_route = $page->rawRoute();
        if ($page->home()) {
            $data->page_route = '/';
        }

        $translated_cache_key = md5('translated_cache_key'.$data->page_route.$pages->getSimplePagesHash());

        $languages = $this->grav['language']->getLanguages();
        $data->languages = $languages;

        if ($this->config->get('plugins.langswitcher.untranslated_pages_behavior') !== 'none') {
            $translated_pages = [];
            foreach ($languages as $language) {
                $translated_pages[$language] = null;
                $page_name_without_ext = substr($page->name(), 0, -(strlen($page->extension())));
                $translated_page_path = $page->path() . DS . $page_name_without_ext . '.' . $language . '.md';
                if (!file_exists($translated_page_path) and $language == $this->grav['language']->getDefault()) {
                    $translated_page_path = $page->path() . DS . $page_name_without_ext . '.md';
                }
                if (file_exists($translated_page_path)) {
                    $translated_page = new Page();
                    $translated_page->init(new \SplFileInfo($translated_page_path), $language . '.md');
                    $translated_pages[$language] = $translated_page;
                }
            }
            $data->translated_pages = $translated_pages;
        }

        $language = $this->grav['language'];
        $active = $language->getActive() ?? $language->getDefault();

        if ($this->config->get('plugins.langswitcher.translated_urls', true)) {
            $data->translated_routes = $cache->fetch($translated_cache_key) ?: [];

            if (empty($data->translated_routes)) {
                $translate_langs = $data->languages;

                if (($key = array_search($active, $translate_langs)) !== false) {
                    $data->translated_routes[$active] = $page->url();
                    unset($translate_langs[$key]);
                }

                foreach ($translate_langs as $lang) {
                    $data->translated_routes[$lang] = $this->getTranslatedUrl($lang, $page->path());
                    if (is_null($data->translated_routes[$lang])) {
                        $data->translated_routes[$lang] = $data->page_route;
                    }
                }
                // Reset pages to current active language
                $language->init();
                $language->setActive($active);
                $this->grav['pages']->reset();
                $cache->save($translated_cache_key, $data->translated_routes);
            }
        }

        $data->current = $language->getLanguage();

        $this->grav['twig']->twig_vars['langswitcher'] = $this->grav['langswitcher'] = $data;

        if ($this->config->get('plugins.langswitcher.built_in_css')) {
            $this->grav['assets']->add('plugin://langswitcher/css/langswitcher.css');
        }
    }

    public function getNativeName($code) {

    }
}
