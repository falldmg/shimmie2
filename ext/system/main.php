<?php
/**
 * Name: System
 * Author: Matthew Barbour <matthew@darkholme.net>
 * License: MIT
 * Description: Provides system screen
 */

class System extends Extension
{
    public function onPageRequest(PageRequestEvent $event)
    {
        global $page, $user;

        if ($event->page_matches("system")) {
            $e = new PageSubNavBuildingEvent("system");
            send_event($e);
            usort($e->links, "sort_nav_links");
            $link = $e->links[0]->link;

            $page->set_redirect($link->make_link());
            $page->set_mode(PageMode::REDIRECT);
        }
    }
    public function onPageNavBuilding(PageNavBuildingEvent $event)
    {
        $event->add_nav_link("system", new Link('system'), "System");
    }


}
