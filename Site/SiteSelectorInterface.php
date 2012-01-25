<?php
/*
 * This file is part of the Sonata project.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sonata\PageBundle\Site;

use Sonata\PageBundle\Model\SiteInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;

interface SiteSelectorInterface
{
    /**
     * @return \Sonata\PageBundle\Model\SiteInterface
     */
    function retrieve();

    /**
     * @param \Sonata\PageBundle\Model\SiteInterface $site
     * @return void
     */
    function set(SiteInterface $site);

    /**
     * @return \Symfony\Component\Routing\RequestContext
     */
    function getRequestContext();

    /**
     * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
     * @return void
     */
    function onKernelRequest(GetResponseEvent $event);

    /**
     * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
     * @return void
     */
    function onKernelRequestRedirect(GetResponseEvent $event);
}