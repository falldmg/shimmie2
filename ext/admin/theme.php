<?php declare(strict_types=1);
use function MicroHTML\INPUT;

class AdminPageTheme extends Themelet
{
    /*
     * Show the basics of a page, for other extensions to add to
     */
    public function display_page()
    {
        global $page;

        $page->set_title("Admin Tools");
        $page->set_heading("Admin Tools");
        $page->add_block(new NavBlock());
    }

    protected function button(string $name, string $action, bool $protected=false): string
    {
        $c_protected = $protected ? " protected" : "";
        $html = make_form(make_link("admin/$action"), "POST", false, "admin$c_protected");
        if ($protected) {
            $html .= "<input type='submit' id='$action' value='$name' disabled='disabled'>";
            $html .= "<input type='checkbox' onclick='$(\"#$action\").attr(\"disabled\", !$(this).is(\":checked\"))'>";
        } else {
            $html .= "<input type='submit' id='$action' value='$name'>";
        }
        $html .= "</form>\n";
        return $html;
    }

    /*
     * Show a form which links to admin_utils with POST[action] set to one of:
     *  'lowercase all tags'
     *  'recount tag use'
     *  etc
     */
    public function display_form()
    {
        global $page, $database;

        $html = "";
        $html .= $this->button("All tags to lowercase", "lowercase_all_tags", true);
        $html .= $this->button("Recount tag use", "recount_tag_use", false);
        $page->add_block(new Block("Misc Admin Tools", $html));

        $html = (string)SHM_SIMPLE_FORM(
            "admin/set_tag_case",
            INPUT(["type"=>'text', "name"=>'tag', "placeholder"=>'Enter tag with correct case', "class"=>'autocomplete_tags', "autocomplete"=>'off']),
            SHM_SUBMIT('Set Tag Case'),
        );
        $page->add_block(new Block("Set Tag Case", $html));
    }
}
