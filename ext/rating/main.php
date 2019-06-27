<?php
/*
 * Name: Image Ratings
 * Author: Shish <webmaster@shishnet.org>
 * Link: http://code.shishnet.org/shimmie2/
 * License: GPLv2
 * Description: Allow users to rate images "safe", "questionable" or "explicit"
 * Documentation:
 *  This shimmie extension provides filter:
 *  <ul>
 *    <li>rating = (safe|questionable|explicit|unknown)
 *      <ul>
 *        <li>rating=s -- safe images
 *        <li>rating=q -- questionable images
 *        <li>rating=e -- explicit images
 *        <li>rating=u -- Unknown rating
 *        <li>rating=sq -- safe and questionable images
 *      </ul>
 *  </ul>
 */

 /**
 * @global Rating[] $_shm_ratings
 */
global $_shm_ratings;
$_shm_ratings = [];

class Rating {
    /**
     * @var string
     */
    public $name = null;

    /**
     * @var string
     */
    public $code = null;

    /**
     * @var string
     */
    public $search_term = null;

    /**
     * @var int
     */
    public $order = 0;

    public function __construct( string $code, string $name, string $search_term, int $order)
    {
        global $_shm_ratings;

        if(strlen($code)!=1) {
            throw new Exception("Rating code must be exactly one character");
        }
        if($search_term[0]!=$code) {
            throw new Exception("Code must be the same as the first letter of search_term");
        }

        $this->name = $name;
        $this->code = $code;
        $this->search_term = $search_term;
        $this->order = $order;

        if($code=="u"&&array_key_exists("u",$_shm_ratings)) {
            throw new Exception("u is a reserved rating code that cnanot be overridden");
        }
        $_shm_ratings[$code] = $this;
    }
}

new Rating("s", "Safe", "safe", 0);
new Rating("q", "Questionable", "questionable", 500);
new Rating("e", "Explicit", "explicit", 1000);
new Rating("u", "Unrated", "unrated", 99999);
@include_once "data/config/ratings.conf.php";

class RatingSetEvent extends Event
{
    /** @var Image */
    public $image;
    /** @var string  */
    public $rating;

    public function __construct(Image $image, string $rating)
    {
        global $_shm_ratings;

        assert(in_array($rating, array_keys($_shm_ratings)));

        $this->image = $image;
        $this->rating = $rating;
    }
}

class Ratings extends Extension
{
    protected $db_support = [DatabaseDriver::MYSQL, DatabaseDriver::PGSQL];

    private $search_regexp;


    public function __construct() {
        parent::__construct();

        global $_shm_ratings;

        $codes = implode("",array_keys($_shm_ratings));
        $search_terms = [];
        foreach($_shm_ratings as $key=>$rating) {
            array_push($search_terms, $rating->search_term);
        }
        $this->search_regexp = "/^rating[=|:](?:([".$codes."]+)|(".
            implode("|", $search_terms)."|unknown))$/D";
    }

    public function get_priority(): int
    {
        return 50;
    }

    public function onInitExt(InitExtEvent $event)
    {
        global $config, $_shm_user_classes, $_shm_ratings;
        
        if ($config->get_int("ext_ratings2_version") < 4) {
            $this->install();
        }

        foreach(array_keys($_shm_user_classes) as $key){
            if($key=="base"||$key=="hellbanned") {
                continue;
            }
            $config->set_default_array("ext_rating_".$key."_privs", array_keys($_shm_ratings));
        }


    }
    
    public function onSetupBuilding(SetupBuildingEvent $event)
    {
        global $config, $_shm_user_classes, $_shm_ratings;

		$ratings = array_values($_shm_ratings);
		usort($ratings, function($a, $b) {
			return $a->order <=> $b->order;
        });
        $options = [];
        foreach($ratings as $key => $rating) {			
			$options[$rating->name] = $rating->code;
		}

        $sb = new SetupBlock("Image Ratings");
        foreach(array_keys($_shm_user_classes) as $key){
            if($key=="base"||$key=="hellbanned") {
                continue;
            }
            $sb->add_multichoice_option("ext_rating_".$key."_privs", $options, "<br/>".$key.": ");
        }

        $event->panel->add_block($sb);
    }
    
    // public function onPostListBuilding(PostListBuildingEvent $event)
    // {
    //     global $user;
    //     if ($user->is_admin() && !empty($event->search_terms)) {
    //         $this->theme->display_bulk_rater(Tag::implode($event->search_terms));
    //     }
    // }

    
    public function onDisplayingImage(DisplayingImageEvent $event)
    {
        global $user, $page;
        /**
         * Deny images upon insufficient permissions.
         **/
        $user_view_level = Ratings::get_user_privs($user);
        if (!in_array($event->image->rating, $user_view_level)) {
            $page->set_mode(PageMode::REDIRECT);
            $page->set_redirect(make_link("post/list"));
        }
    }
    
    public function onRatingSet(RatingSetEvent $event)
    {
        if (empty($event->image->rating)) {
            $old_rating = "";
        } else {
            $old_rating = $event->image->rating;
        }
        $this->set_rating($event->image->id, $event->rating, $old_rating);
    }
    
    public function onImageInfoBoxBuilding(ImageInfoBoxBuildingEvent $event)
    {
        $event->add_part($this->theme->get_rater_html($event->image->id, $event->image->rating, $this->can_rate()), 80);
    }
    
    public function onImageInfoSet(ImageInfoSetEvent $event)
    {
        if ($this->can_rate() && isset($_POST["rating"])) {
            $rating = $_POST["rating"];
            if (Ratings::rating_is_valid($rating)) {
                send_event(new RatingSetEvent($event->image, $rating));
            }
        }
    }

    public function onParseLinkTemplate(ParseLinkTemplateEvent $event)
    {
        $event->replace('$rating', $this->rating_to_human($event->image->rating));
    }

    public function onHelpPageBuilding(HelpPageBuildingEvent $event)
    {
        global $user;

        if($event->key===HelpPages::SEARCH) {
            $block = new Block();
            $block->header = "Ratings";

            $ratings = self::get_sorted_ratings();

            $block->body = $this->theme->get_help_html($ratings);
            $event->add_block($block);
        }
    }

    public function onSearchTermParse(SearchTermParseEvent $event)
    {
        global $user, $_shm_ratings;
        
        $matches = [];
        if (is_null($event->term) && $this->no_rating_query($event->context)) {
            $set = Ratings::privs_to_sql(Ratings::get_user_privs($user));
            $event->add_querylet(new Querylet("rating IN ($set)"));
        }


        if (preg_match($this->search_regexp, strtolower($event->term), $matches)) {
            $ratings = $matches[1] ? $matches[1] : $matches[2][0];

            $ratings = array_intersect(str_split($ratings), Ratings::get_user_privs($user));

            $set = "'" . join("', '", $ratings) . "'";
            $event->add_querylet(new Querylet("rating IN ($set)"));
        }
    }

    public function onTagTermParse(TagTermParseEvent $event)
    {
        global $user;
        $matches = [];

        if (preg_match($this->search_regexp, strtolower($event->term), $matches) && $event->parse) {
            $ratings = $matches[1] ? $matches[1] : $matches[2][0];
            $ratings = array_intersect(str_split($ratings), Ratings::get_user_privs($user));

            $rating = $ratings[0];
            
            $image = Image::by_id($event->id);

            $re = new RatingSetEvent($image, $rating);

            send_event($re);
        }

        if (!empty($matches)) {
            $event->metatag = true;
        }
    }
    
    public function onBulkActionBlockBuilding(BulkActionBlockBuildingEvent $event)
    {
        global $user;

        if ($user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
            $event->add_action("bulk_rate","Set Rating","",$this->theme->get_selection_rater_html("bulk_rating"));
        }
    }

    public function onBulkAction(BulkActionEvent $event)
    {
        global $user;

        switch ($event->action) {
            case "bulk_rate":
                if (!isset($_POST['bulk_rating'])) {
                    return;
                }
                if ($user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
                    $rating = $_POST['bulk_rating'];
                    $total = 0;
                    foreach ($event->items as $image) {
                        send_event(new RatingSetEvent($image, $rating));
                        $total++;
                    }
                    flash_message("Rating set for $total items");
                }
                break;
        }
    }

    public function onPageRequest(PageRequestEvent $event)
    {
        global $user, $page;
        
        if ($event->page_matches("admin/bulk_rate")) {
            if (!$user->can(Permissions::BULK_EDIT_IMAGE_RATING)) {
                throw new PermissionDeniedException();
            } else {
                $n = 0;
                while (true) {
                    $images = Image::find_images($n, 100, Tag::explode($_POST["query"]));
                    if (count($images) == 0) {
                        break;
                    }
                    
                    reset($images); // rewind to first element in array.
                    
                    foreach ($images as $image) {
                        send_event(new RatingSetEvent($image, $_POST['rating']));
                    }
                    $n += 100;
                }
                #$database->execute("
                #	update images set rating=? where images.id in (
                #		select image_id from image_tags join tags
                #		on image_tags.tag_id = tags.id where tags.tag = ?);
                #	", array($_POST["rating"], $_POST["tag"]));
                $page->set_mode(PageMode::REDIRECT);
                $page->set_redirect(make_link("post/list"));
            }
        }
    }

    public static function get_user_privs(User $user): array
    {
        global $config;

        return $config->get_array("ext_rating_".$user->class->name."_privs");
    }

    public static function privs_to_sql(array $privs): string
    {
        $arr = [];
        foreach($privs as $i) {
            $arr[] = "'" . $i . "'";
        }
        if(sizeof($arr)==0) {
            return "' '";
        }
        $set = join(', ', $arr);
        return $set;
    }

    public static function rating_to_human(string $rating): string
    {
        global $_shm_ratings;

        if(array_key_exists($rating, $_shm_ratings)) {
            return $_shm_ratings[$rating]->name;
        }
        return "Unknown";
    }

    public static function rating_is_valid(string $rating): bool
    {
        global $_shm_ratings;

        return in_array($rating, array_keys($_shm_ratings));
    }

    /**
     * FIXME: this is a bit ugly and guessey, should have proper options
     */
    private function can_rate(): bool
    {
        global $config, $user;
        if ($user->can("edit_image_rating")) {
            return true;
        }
        return false;
    }

    /**
     * #param string[] $context
     */
    private function no_rating_query(array $context): bool
    {
        foreach ($context as $term) {
            if (preg_match("/^rating[=|:]/", $term)) {
                return false;
            }
        }
        return true;
    }

    private function install()
    {
        global $database, $config;

        if ($config->get_int("ext_ratings2_version") < 1) {
            $database->Execute("ALTER TABLE images ADD COLUMN rating CHAR(1) NOT NULL DEFAULT 'u'");
            $database->Execute("CREATE INDEX images__rating ON images(rating)");
            $config->set_int("ext_ratings2_version", 3);
        }

        if ($config->get_int("ext_ratings2_version") < 2) {
            $database->Execute("CREATE INDEX images__rating ON images(rating)");
            $config->set_int("ext_ratings2_version", 2);
        }

		if($config->get_int("ext_ratings2_version") < 3) {
            $database->Execute("UPDATE images SET rating = 'u' WHERE rating is null");
            switch ($database->get_driver_name()) {
                case DatabaseDriver::MYSQL:
                    $database->Execute("ALTER TABLE images CHANGE rating rating CHAR(1) NOT NULL DEFAULT 'u'");
                    break;
                case DatabaseDriver::PGSQL:
                    $database->Execute("ALTER TABLE images ALTER COLUMN rating SET DEFAULT 'u'");
                    $database->Execute("ALTER TABLE images ALTER COLUMN rating SET NOT NULL");
                    break;
            }
			$config->set_int("ext_ratings2_version", 3);
		}

        if ($config->get_int("ext_ratings2_version") < 4) {
            $value = $config->get_string("ext_rating_anon_privs");
            $config->set_array("ext_rating_anonymous_privs", str_split($value));
            $value = $config->get_string("ext_rating_user_privs");
            $config->set_array("ext_rating_user_privs", str_split($value));
            $value = $config->get_string("ext_rating_admin_privs");
            $config->set_array("ext_rating_admin_privs", str_split($value));
            $config->set_int("ext_ratings2_version", 4);
        }
    }

    private function set_rating(int $image_id, string $rating, string $old_rating)
    {
        global $database;
        if ($old_rating != $rating) {
            $database->Execute("UPDATE images SET rating=? WHERE id=?", [$rating, $image_id]);
            log_info("rating", "Rating for Image #{$image_id} set to: ".$this->rating_to_human($rating));
        }
    }
}
