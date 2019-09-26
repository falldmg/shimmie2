<?php

/**
 * Name: [Beta] Forum
 * Author: Sein Kraft <mail@seinkraft.info>
 *         Alpha <alpha@furries.com.ar>
 * License: GPLv2
 * Description: Rough forum extension
 * Documentation:
 */

class ForumInfo extends ExtensionInfo
{
    public const KEY = "dorum";

    public $key = self::KEY;
    public $name = "Forum";
    public $authors = ["Sein Kraft"=>"mail@seinkraft.info","Alpha"=>"alpha@furries.com.ar"];
    public $license = self::LICENSE_GPLV2;
    public $description = "Rough forum extension";
}
