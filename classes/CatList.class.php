<?php
/**
*   List categories on the home page
*
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2016 Lee Garner <lee@leegarner.com>
*   @package    classifieds
*   @version    1.1.0
*   @license    http://opensource.org/licenses/gpl-2.0.php 
*               GNU Public License v2 or later
*   @filesource
*/


/**
*   @class adCatList
*   @package classifieds
*   Create a listing of ads
*/
class adCatList
{

    /**
    *   When no category is given, show a table of all categories
    *   along with the count of ads for each.  
    *   Returns the results from the category
    *   list function, chosen based on the display mode
    *   @return string      HTML for category listing page
    */
    public static function Render()
    {
        global $_CONF_ADVT;
        switch ($_CONF_ADVT['catlist_dispmode']) {
        case 'blocks':
            return self::_Blocks();
            break;

        default:
            return self::_Normal();
            break;
        }
    }


    /**
    *   Create a "normal" list of categories, using text links
    *
    *   @return string      HTML for category listing page
    */
    private static function _Normal()
    {
        global $_CONF, $_TABLES, $LANG_ADVT, $_CONF_ADVT;

        $T = new Template($_CONF_ADVT['path'] . '/templates');
        $T->set_file('page', 'catlist.thtml');

        // Get all the root categories
        $sql = "SELECT * FROM {$_TABLES['ad_category']} 
                WHERE papa_id = 0 " . COM_getPermSQL('AND', 0, 2) .
                " ORDER BY cat_name ASC";
        $cats = DB_query($sql);
        if (!$cats) return CLASSIFIEDS_errorMsg($LANG_ADVT['database_error'], 'alert');

        // If no root categories exist, display just return a message
        if (DB_numRows($cats) == 0) {
            $T->set_var('no_cat_found', 
                "<p align=\"center\" class=\"headmsg\">
                $LANG_ADVT[no_cat_found]</p>\n");
            $T->parse('output', 'page');
            return $T->finish($T->get_var('output'));
        }

        $T->set_block('page', 'CatRows', 'CRow');

        $i = 1;
        USES_classifieds_class_category();
        while ($catsrow = DB_fetchArray($cats)) {
            // For each category, find the total ad count (including subcats)
            // and display the subcats below it.
            $T->set_var(array(
                'rowstart'  => $i % 2 == 1 ? "<tr>\n" : '',
                'cat_url'   => CLASSIFIEDS_makeUrl('home', $catsrow['cat_id']),
                'cat_name'  => $catsrow['cat_name'],
                'cat_ad_count' => adCategory::TotalAds($catsrow['cat_id']),
                'image' => $catsrow['image'] ? adCategory::thumbUrl($catsrow['image']) : '',
            ) );

            $sql = "SELECT * FROM {$_TABLES['ad_category']} 
                    WHERE papa_id={$catsrow['cat_id']} " .
                        COM_getPermSQL('AND', 0, 2) . "
                    ORDER BY cat_name ASC";
            //echo $sql;die;
            $subcats = DB_query($sql);
            if (!$subcats) return CLASSIFIEDS_errorMsg($LANG_ADVT['database_error'], 'alert');

            $num = DB_numRows($subcats);
            $time = time();
            // Earliest time to be considered "new"
            $newtime = $time - 3600 * 24 * $_CONF_ADVT['newcatdays'];
            $subcatlist = '';

            $j = 1;
            while ($subcatsrow = DB_fetchArray($subcats)) {
                $isnew = $subcatsrow['add_date'] > $newtime ? 
                    "<img src=\"{$_CONF['site_url']}/{$_CONF_ADVT['pi_name']}/images/new.gif\" align=\"top\">" : '';

                $subcatlist .= '<a href="'.
                        CLASSIFIEDS_makeURL('home', $subcatsrow['cat_id']). '">'.
                        "{$subcatsrow['cat_name']}</a>&nbsp;(" .
                        adCategory::TotalAds($subcatsrow['cat_id']). ")&nbsp;{$isnew}";

                if ($num != $j)
                    $subcatlist .= ", ";

                $j++;
            }

            $T->set_var('subcatlist', $subcatlist);
            $T->set_var('rowend', $i % 2 == 0 ? "</tr>\n" : "");
            $i++;

            $T->parse('CRow', 'CatRows', true);
        }

        $T->parse('output', 'page');
        return $T->finish($T->get_var('output'));
    }


    /**
    *   Create a category listing page showing the categories in block styling.
    *
    *   @return string      HTML for category listing page
    */
    private static function _Blocks()
    {
        global $_CONF, $_TABLES, $LANG_ADVT, $_CONF_ADVT;
        global $CatListcolors;

        $T = new Template($_CONF_ADVT['path'] . '/templates');
        $T->set_file('page', 'catlist_blocks.thtml');

        // Get all the root categories
        $sql = "SELECT * FROM {$_TABLES['ad_category']} 
                WHERE papa_id='' " .
                    COM_getPermSQL('AND', 0, 2) .
                " ORDER BY cat_name ASC";
        //echo $sql;die;
        $cats = DB_query($sql);
        if (!$cats) return CLASSIFIEDS_errorMsg($LANG_ADVT['database_error'], 'alert');

        // If no root categories exist, display just return a message
        if (DB_numRows($cats) == 0) {
            $T->set_var('no_cat_found', 
                "<p align=\"center\" class=\"headmsg\">
                $LANG_ADVT[no_cat_found]</p>\n");
            $T->parse('output', 'page');
            return $T->finish($T->get_var('output'));
        }

        $max = count($CatListcolors);

        $i = 0;
        USES_classifieds_class_category();
        while ($catsrow = DB_fetchArray($cats)) {
            if ($catsrow['fgcolor'] == '' || $catsrow['bgcolor'] == '') {
                if ($i >= $max) $i = 0;
                $bgcolor = $CatListcolors[$i][0];
                $fgcolor = $CatListcolors[$i][1];
                $hdcolor = $CatListcolors[$i][2];
                $i++;
            } else {
                $fgcolor = $catsrow['fgcolor'];
                $bgcolor = $catsrow['bgcolor'];
            } 

            // For each category, find the total ad count (including subcats)
            // and display the subcats below it.
            $T->set_block('page', 'CatDiv', 'Div');
            $T->set_var(array(
                'bgcolor'   => $bgcolor,
                'fgcolor'   => $fgcolor,
                'cat_url'   => CLASSIFIEDS_makeUrl('home',$catsrow['cat_id']),
                'cat_name'  => $catsrow['cat_name'],
                'cat_desc'  => $catsrow['description'],
                'cat_ad_count' => adCategory::TotalAds($catsrow['cat_id']),
                'image' => $catsrow['image'] ? adCategory::thumbUrl($catsrow['image']): '',
            ) );
            $T->parse('Div', 'CatDiv', true);
        }

        $T->parse('output', 'page');
        return $T->finish($T->get_var('output'));
    }
}

?>
