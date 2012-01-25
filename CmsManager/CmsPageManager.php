<?php
/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\CmsManager;

use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

use Sonata\PageBundle\Model\BlockInterface;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\BlockManagerInterface;
use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Block\BlockServiceInterface;
use Sonata\PageBundle\Cache\CacheInterface;
use Sonata\PageBundle\Cache\Invalidation\InvalidationInterface;
use Sonata\PageBundle\Cache\CacheElement;
use Sonata\PageBundle\Model\SiteInterface;

use Sonata\AdminBundle\Admin\AdminInterface;
use Sonata\AdminBundle\Validator\ErrorElement;

use Symfony\Component\Routing\RouterInterface;

/**
 * The Manager class is in charge of retrieving the correct page (cms page or action page)
 *
 * An action page is linked to a symfony action and a cms page is a standalone page.
 *
 *
 * @author     Thomas Rabaix <thomas.rabaix@sonata-project.org>
 */
class CmsPageManager extends BaseCmsPageManager
{
    protected $blockManager;

    protected $pageAdmin;

    protected $blockAdmin;

    /**
     * @param \Sonata\PageBundle\Model\PageManagerInterface $pageManager
     * @param \Sonata\PageBundle\Model\BlockManagerInterface $blockManager
     * @param \Symfony\Component\Templating\EngineInterface $templating
     * @param \Sonata\PageBundle\Cache\Invalidation\InvalidationInterface $cacheInvalidation
     * @param \Symfony\Component\Routing\RouterInterface $router
     */
    public function __construct(
        EngineInterface $templating,
        InvalidationInterface $cacheInvalidation,
        RouterInterface $router,
        array $httpErrorCodes = array(),
        PageManagerInterface $pageManager,
        BlockManagerInterface $blockManager
    )
    {
        parent::__construct($templating, $cacheInvalidation, $router, $httpErrorCodes);

        $this->pageManager  = $pageManager;
        $this->blockManager = $blockManager;
    }

    /**
     * {@inheritdoc}
     */
    public function getCode()
    {
        return 'page';
    }

    /**
     * {@inheritdoc}
     */
    protected function getRenderPageParams(PageInterface $page)
    {
        return array_merge(parent::getRenderPageParams($page), array(
            'page_admin'    => $this->getPageAdmin(),
            'block_admin'   => $this->getBlockAdmin(),
        ));
    }

    /**
     * Return a PageInterface instance depends on the $page argument
     *
     * @param mixed $page
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    protected function getPage(SiteInterface $site, $page)
    {
        if (is_string($page) && substr($page, 0, 1) == '/') {
            $page = $this->getPageByUrl($site, $page);
        } else if (is_string($page)) { // page is a slug, load the related page
            $page = $this->getPageByRouteName($site, $page);
        } else if ( is_numeric($page)) {
            $page = $this->getPageById($page);
        } else if (!$page) { // get the current page
            $page = $this->getCurrentPage();
        }

        if (!$page instanceof PageInterface) {
            throw new \RunTimeException('Unable to retrieve the page');
        }

        return $page;
    }

    /**
     * {@inheritdoc}
     */
    public function findContainer($name, PageInterface $page, BlockInterface $parentContainer = null)
    {
        $container = false;

        if ($parentContainer) {
            // parent container is set, nothing to find, don't need to loop across the
            // name to find the correct container (main template level)
            $container = $parentContainer;
        }

        // first level blocks are containers
        if (!$container && $page->getBlocks()) {
            foreach ($page->getBlocks() as $block) {
                if ($block->getSetting('name') == $name) {

                    $container = $block;
                    break;
                }
            }
        }

        if (!$container) {
            $container = $this->blockManager->createNewContainer(array(
                'enabled' => true,
                'page' => $page,
                'name' => $name,
                'position' => 1
            ));

            if ($parentContainer) {
                $container->setParent($parentContainer);
            }

            $this->blockManager->save($container);
        }

        return $container;
    }

    /**
     * @param $fieldName
     * @param $value
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    protected function getPageBy(SiteInterface $site = null, $fieldName, $value) {
        if ('id' == $fieldName) {
            $id = $value;
        } elseif(isset($this->pageReferences[$fieldName][$value])) {
            $id = $this->pageReferences[$fieldName][$value];
        } else {
            $id = null;
        }

        if (null === $id || !isset($this->pages[$id])) {
            $this->pages[$id] = false;

            $parameters = array(
                $fieldName => $value,
            );

            if ($site) {
                $parameters['site'] = $site->getId();
            }

            $page = $this->getPageManager()->findOneBy($parameters);

            if ($page) {
                $this->loadBlocks($page);
                $id = $page->getId();

                if ($fieldName != 'id') {
                    $this->pageReferences[$fieldName][$value] = $id;
                }

                $this->pages[$id] = $page;
            }
        }

        return $this->pages[$id];
    }

    /**
     * {@inheritdoc}
     */
    public function getPageByUrl(SiteInterface $site, $url)
    {
        return $this->getPageBy($site, 'url', $url);
    }

    /**
     * {@inheritdoc}
     */
    public function getPageByRouteName(SiteInterface $site, $routeName, $create = true)
    {
        $page = $this->getPageBy($site, 'routeName', $routeName);

        if (!$page && !$create) {
            throw new \RuntimeException(sprintf('Unable to find the page : %s', $routeName));
        } else if (!$page) {
            $page = $this->createPage($site, $routeName);
        }

        return $page;
    }

    /**
     * {@inheritdoc}
     */
    public function getPageByName(SiteInterface $site, $name, $create = true)
    {
        $page = $this->getPageBy($site, 'name', $name);

        if (!$page && !$create) {
            throw new \RuntimeException(sprintf('Unable to find the page : %s', $name));
        } elseif (!$page) {
            $page = $this->createPage($site, $name);
        }

        return $page;
    }

    /**
     * {@inheritdoc}
     */
    public function getPageById($id)
    {
        return $this->getPageBy(null, 'id', $id);
    }

    /**
     * @throws \RuntimeException
     * @param string $routeName
     * @return \Sonata\PageBundle\Model\PageInterface
     */
    public function createPage(SiteInterface $site, $routeName)
    {
        $page = $this->getPageManager()->createNewPage(array(
            'routeName' => $routeName,
            'name'      => $routeName,
        ));

        $page->setSite($site);

        $this->getPageManager()->save($page);

        return $page;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlock($id)
    {
        if (!isset($this->blocks[$id])) {
            $this->blocks[$id] = $this->blockManager->getBlock($id);
        }

        return $this->blocks[$id];
    }

    /**
     * load all the related nested blocks linked to one page.
     *
     * @param \Sonata\PageBundle\Model\PageInterface $page
     * @return void
     */
    private function loadBlocks(PageInterface $page)
    {
        $blocks = $this->blockManager->loadPageBlocks($page);

        // save a local cache
        foreach ($blocks as $block) {
            $this->blocks[$block->getId()] = $block;
        }
    }

    /**
     * @return \Sonata\PageBundle\Model\BlockManagerInterface
     */
    public function getBlockManager()
    {
        return $this->blockManager;
    }

    /**
     * @param \Sonata\AdminBundle\Admin\AdminInterface $blockAdmin
     * @return void
     */
    public function setBlockAdmin(AdminInterface $blockAdmin)
    {
        $this->blockAdmin = $blockAdmin;
    }

    /**
     * @return \Sonata\AdminBundle\Admin\AdminInterface
     */
    public function getBlockAdmin()
    {
        return $this->blockAdmin;
    }

    /**
     * @param \Sonata\AdminBundle\Admin\AdminInterface $pageAdmin
     * @return void
     */
    public function setPageAdmin(AdminInterface $pageAdmin)
    {
        $this->pageAdmin = $pageAdmin;
    }

    /**
     * @return Sonata\AdminBundle\Admin\AdminInterface
     */
    public function getPageAdmin()
    {
        return $this->pageAdmin;
    }

    /**
     * @param \Sonata\AdminBundle\Validator\ErrorElement $errorElement
     * @param \Sonata\PageBundle\Model\BlockInterface $block
     * @return
     */
    public function validateBlock(ErrorElement $errorElement, BlockInterface $block)
    {
        if (!$block->getId() && !$block->getType()) {
            return;
        }

        $service = $this->getBlockService($block);
        $service->validateBlock($this, $errorElement, $block);
    }

    /**
     * @return \Symfony\Component\Routing\RouterInterface
     */
    public function getRouter()
    {
        return $this->router;
    }
}