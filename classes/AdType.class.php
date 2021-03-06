<?php
/**
*   Class to manage ad types
*
*   @author     Lee Garner <lee@leegarner.com>
*   @copyright  Copyright (c) 2009-2016 Lee Garner <lee@leegarner.com>
*   @package    classifieds
*   @version    1.1.0
*   @license    http://opensource.org/licenses/gpl-2.0.php
*               GNU Public License v2 or later
*   @filesource
*/
namespace Classifieds;

/**
*   Class for ad type
*   @package classifieds
*/
class AdType
{
    /** Properties array
     *  @var array */
    var $properties;

    /** Error string or value, to be accessible by the calling routines.
     *  @var mixed */
    public  $Error;


    /**
    *   Constructor.
    *   Reads in the specified class, if $id is set.  If $id is zero,
    *   then a new entry is being created.
    *
    *   @param integer $id Optional type ID
    */
    public function __construct($id=0)
    {
        $id = (int)$id;
        if ($id < 1) {
            $this->id = 0;
            $this->description = '';
            $this->enabled = 1;
        } else {
            $this->id = $id;
            $this->ReadOne();
        }
    }

    public function __set($key, $value)
    {
        switch ($key) {
        case 'id':
            $this->properties[$key] = (int)$value;
            break;
        case 'description':
            $this->properties[$key] = trim($value);
            break;
        case 'enabled':
            $this->properties[$key] = $value == 1 ? 1 : 0;
            break;
        }
    }

    public function __get($key)
    {
        if (isset($this->properties[$key]))
            return $this->properties[$key];
        else
            return NULL;
    }


    /**
    *   Sets all variables to the matching values from $rows
    *
    *   @param array $row Array of values, from DB or $_POST
    */
    public function SetVars($row)
    {
        if (!is_array($row)) return;

        $this->id = $row['id'];
        $this->description = $row['description'];
        $this->enabled = isset($row['enabled']) && $row['enabled'] ? 1 : 0;
    }


    /**
    *   Read one as type from the database and populate the local values.
    *
    *   @param integer $id Optional ID.  Current ID is used if zero
    */
    public function ReadOne($id = 0)
    {
        global $_TABLES;

        $id = (int)$id;
        if ($id == 0) $id = $this->id;
        if ($id == 0) {
            $this->error = 'Invalid ID in ReadOn()';
            return;
        }

        $result = DB_query("SELECT * from {$_TABLES['ad_types']}
                            WHERE id=$id");
        $row = DB_fetchArray($result, false);
        $this->SetVars($row);
    }


    /**
    *   Delete the curret cls/div record from the database
    */
    public function Delete()
    {
        global $_TABLES;

        if ($this->id > 0)
            DB_delete($_TABLES['ad_types'], 'id', $this->id);

        $this->id = 0;
    }


    /**
    *   Adds the current values to the databae as a new record
    *
    *   @param  array   $vals   Optional array of values to set
    *   @return boolean     True on success, False on error
    */
    public function Save($vals = NULL)
    {
        global $_TABLES;

        if (is_array($vals)) {
            $this->SetVars($vals);
        }

        if (!$this->isValidRecord()) {
            return false;
        }

        if ($this->id == 0) {
            $sql1 = "INSERT INTO {$_TABLES['ad_types']} SET ";
            $sql3 = '';
        } else {
            $sql1 = "UPDATE {$_TABLES['ad_types']} SET ";
            $sql3 = " WHERE id=" . $this->id;
        }
        $sql2 = "description = '" . DB_escapeString($this->description) . "',
                enabled = {$this->enabled}";
        $sql = $sql1 . $sql2 . $sql3;
        $res = DB_query($sql);
        return DB_error() ? false : true;
    }


    /**
    *   Determines if the current values are valid.
    *
    *   @return boolean True if ok, False otherwise.
    */
    public function isValidRecord()
    {
        if ($this->description == '') {
            return false;
        } else {
            return true;
        }
    }


    /**
    *   Creates the edit form
    *
    *   @param  integer $id Optional ID, current record used if zero
    *   @return string      HTML for edit form
    */
    public function showForm($id = 0)
    {
        global $_TABLES, $_CONF, $_CONF_ADVT, $LANG_ADVT, $LANG_ADMIN;

        $id = (int)$id;
        if ($id > 0) $this->Read($id);

        $T = new \Template($_CONF_ADVT['path'] . '/templates');
        if ($_CONF_ADVT['_is_uikit']) {
            $T->set_file('admin','adtypeform.uikit.thtml');
        } else {
            $T->set_file('admin','adtypeform.thtml');
        }
        $T->set_var(array(
            'pi_admin_url'  => $_CONF_ADVT['admin_url'],
            'lang_header'   => $id == 0 ? $LANG_ADVT['newadtypehdr'] : $LANG_ADVT['editadtypehdr'],
            'cancel_url'    => $_CONF_ADVT['admin_url'] . '/index.php?admin=type',
            'show_name'     => $this->showName,
            'type_id'       => $this->id,
            'description'   => htmlspecialchars($this->description),
            'ena_chk'   => $this->enabled == 1 ? 'checked="checked"' : '',
        ) );

        // add a delete button if this ad type isn't used anywhere
        if ($this->id > 0 && !$this->isUsed()) {
            $T->set_var('show_del_btn', 'true');
        } else {
            $T->set_var('show_del_btn', '');
        }
        $T->parse('output','admin');
        return $T->finish($T->get_var('output'));
    }   // function showForm()


    /**
    *   Creates a dropdown selection for the specified list, with the
    *   record corresponding to $sel selected.
    *
    *   @param  integer $sel    Optional item ID to select
    *   @return string      HTML for selection dropdown
    */
    public static function makeSelection($sel=0)
    {
        global $_TABLES;

        return COM_optionList($_TABLES['ad_types'],
                'id,description', $sel, 1, 'enabled=1');
    }   // function makeSelection()


    /**
    *   Sets the "enabled" field to the specified value.
    *
    *   @param  integer     $oldval Original value to change
    *   @param  integer     $id     ID number of element to modify
    *   @return integer     New value (old value if failed)
    */
    public static function toggleEnabled($oldval, $id=0)
    {
        global $_TABLES;

        $id = (int)$id;
        $newval = $oldval == 1 ? 0 : 1;

        $sql = "UPDATE {$_TABLES['ad_types']}
            SET enabled=$newval
            WHERE id=$id";
        //echo $sql;die;
        DB_query($sql, 1);
        if (DB_error()) {
            $retval = $oldval;
        } else {
            $retval = $newval;
        }
        return $retval;
    }


    /**
    *   Determine if this ad type is used by any ads in the database.
    *
    *   @return boolean True if used, False if not
    */
    public function isUsed()
    {
        global $_TABLES;

        if (DB_count($_TABLES['ad_ads'], 'ad_type', $this->id) > 0) {
            return true;
        } else {
            return false;
        }
    }


    /**
    *   Returns the string corresponding to the $id parameter.
    *   Designed to be used standalone; if this is an object,
    *   we already have the description in a variable.
    *   Uses a static variable to hold the descriptions since this can
    *   be called many times for an ad listing.
    *
    *   @param  integer $id     Database ID of the ad type
    *   @return string          Ad Type Description
    */
    public static function getDescription($id)
    {
        global $_TABLES;
        static $desc = array();

        $id = (int)$id;
        if (!isset($desc[$id])) {
            $desc[$id] = DB_getItem($_TABLES['ad_types'], 'description', "id='$id'");
        }
        return $desc[$id];
    }

}   // class AdType

?>
