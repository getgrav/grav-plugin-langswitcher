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
use Twig\TwigFunction;

class LangSwitcherPlugin extends Plugin
{
    /**
     * Cached per-language route maps indexed by language code.
     *
     * @var array
     */
    protected $languageRouteMap = [];

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
            new TwigFunction('native_name', function($key) {
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
     * Build or fetch a cached map of page paths to translated URLs for a given language.
     *
     * @param string $lang
     * @return array
     */
    protected function getLanguageRouteMap($lang)
    {
        if (isset($this->languageRouteMap[$lang])) {
            return $this->languageRouteMap[$lang];
        }

        /** @var Pages $pages */
        $pages = $this->grav['pages'];
        /** @var Cache $cache */
        $cache = $this->grav['cache'];
        /** @var Language $language */
        $language = $this->grav['language'];

        $pages_hash = $pages->getSimplePagesHash();
        $cache_key = null;

        if ($pages_hash !== null) {
            $cache_key = md5('langswitcher_routes_' . $lang . '_' . $pages_hash);
            $cached = $cache->fetch($cache_key);
            if (is_array($cached)) {
                $this->languageRouteMap[$lang] = $cached;

                return $this->languageRouteMap[$lang];
            }
        }

        $map = [];
        $active = $language->getActive() ?? $language->getDefault();
        $restore_language = $lang !== $active;

        if ($restore_language) {
            $language->init();
            $language->setActive($lang);
            $pages->reset();
        }

        foreach ($pages->routes() as $page_path) {
            $page = $pages->get($page_path);
            if ($page) {
                $map[$page_path] = $page->url();
            }
        }

        if ($restore_language) {
            $language->init();
            $language->setActive($active);
            $pages->reset();
        }

        if ($cache_key !== null) {
            $cache->save($cache_key, $map);
        }

        $this->languageRouteMap[$lang] = $map;

        return $this->languageRouteMap[$lang];
    }

    /**
     * Generate localized route based on the translated slugs found through the pages hierarchy
     */
    protected function getTranslatedUrl($lang, $path)
    {
        if (empty($path)) {
            return null;
        }

        $map = $this->getLanguageRouteMap($lang);

        return $map[$path] ?? null;
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

        $data = new \stdClass;
        $data->page_route = $page->rawRoute();
        if ($page->home()) {
            $data->page_route = '/';
        }

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
            $data->translated_routes = [$active => $page->url()];

            foreach ($data->languages as $lang) {
                if ($lang === $active) {
                    continue;
                }

                $translated = $this->getTranslatedUrl($lang, $page->path());
                $data->translated_routes[$lang] = $translated ?? $data->page_route;
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
