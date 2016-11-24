/*  Updates submission form fields based on changes in the category
 *  dropdown.
 */
var xmlHttp;
function ADVTtoggleEnabled(ck, id, type, base_url)
{
  if (ck.checked) {
    newval=1;
  } else {
    newval=0;
  }

  xmlHttp=ADVTgetXmlHttpObject();
  if (xmlHttp==null) {
    alert ("Browser does not support HTTP Request")
    return
  }
  var url=site_admin_url + "/plugins/classifieds/ajax.php?action=toggleEnabled";
  url=url+"&id="+id;
  url=url+"&type="+type;
  url=url+"&newval="+newval;
  url=url+"&sid="+Math.random();
  xmlHttp.onreadystatechange=ADVTstateChanged;
  xmlHttp.open("GET",url,true);
  xmlHttp.send(null);
}

function ADVTstateChanged()
{
  if (xmlHttp.readyState==4 || xmlHttp.readyState=="complete") {
    jsonObj = JSON.parse(xmlHttp.responseText)
    id = jsonObj.id;

    // Get the status and category id
    if (jsonObj.newstate == 1) {
        document.getElementById("enabled_"+id).checked = "checked";
    } else {
        document.getElementById("enabled_"+id).checked = "";
    }
  }
}

function ADVTgetXmlHttpObject()
{
  var objXMLHttp=null
  if (window.XMLHttpRequest)
  {
    objXMLHttp=new XMLHttpRequest()
  }
  else if (window.ActiveXObject)
  {
    objXMLHttp=new ActiveXObject("Microsoft.XMLHTTP")
  }
  return objXMLHttp
}

