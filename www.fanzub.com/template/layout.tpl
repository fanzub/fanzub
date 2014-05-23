<!DOCTYPE html>
<html lang="en">
<head>
<title>{$title}Fanzub</title>
<meta http-equiv="content-type" content="text/html; charset=utf-8" />
{$meta|}
<link rel="stylesheet" type="text/css" href="/include/fanzub.css?20111028" />
<link rel="shortcut icon" href="/favicon.ico" type="image/vnd.microsoft.icon" />
<link rel="alternate" type="application/rss+xml" title="{$title}Fanzub RSS" href="{$rss}" />
<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', 'UA-136268-4']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ga, s);
  })();

</script>
</head>
<body>

<script type="text/javascript">
/* <![CDATA[ */

function Details(id)
{
  if ($("#post"+id).text() == "")
    $("#post"+id).click(function() { $(this).slideToggle("slow"); }).load("/?details="+id,function() { $(this).slideDown("slow"); });
  else
    $("#post"+id).slideToggle("slow");
}

/* ]]> */
</script>
  
<div id="container">
  <div id="header">
    <div id="logo">
      <a href="/"><img src="/images/logo_small.png" width="125" height="35" alt="Fanzub" /></a>
    </div>
    {$menu}
    {$searchbox}
  </div>
  {$body}
</div>

{$footer|}

<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.7/jquery.min.js"></script>
<script type="text/javascript">
/* <![CDATA[ */

var checked = false;
var selector = false;
var dblclick = false;
var disableselect = false;

$(document).ready(function() {
  $("html").mousedown(function(button) {
    if (button.which == 1)
    {
      selector = true;
      checked = !checked;
    }
  });
  $("html").mouseup(function(button) {
    if (button.which == 1)
    {
      selector = false;
    }
  });
  $("table.fanzub a").mouseenter(function() { disableselect = true; });
  $("table.fanzub div.details").mouseenter(function() { disableselect = true; });
  $("table.fanzub a").mouseleave(function() { disableselect = false; });
  $("table.fanzub div.details").mouseleave(function() { disableselect = false; });
  $("table.fanzub tr").mouseover(function() {
    if (selector)
    {
      row = this;
      if ($('input[type="checkbox"]',row).size() == 0)
        row = $(this).prev();
      checkbox = $('input[type="checkbox"]',row);
      $(checkbox).attr("checked",checked);
      if ($(checkbox).attr("checked"))
      {
        $(this).addClass("selected");
        if ($(this).is('.top'))
          $(this).next().addClass("selected");
        else
          $(this).prev().addClass("selected");
      }
      else
      {
        $(this).removeClass("selected");
        if ($(this).is('.top'))
          $(this).next().removeClass("selected");
        else
          $(this).prev().removeClass("selected");
      }
    }
  });
  $("table.fanzub tr").click(function() {
    if (disableselect)
      return;
    row = this;
    if ($('input[type="checkbox"]',row).size() == 0)
      row = $(this).prev();
    checkbox = $('input[type="checkbox"]',row);
    checkbox.attr("checked",!checkbox.attr("checked"));
    if ($(checkbox).attr("checked"))
    {
      $(this).addClass("selected");
      if ($(this).is('.top'))
        $(this).next().addClass("selected");
      else
        $(this).prev().addClass("selected");
    }
    else
    {
      $(this).removeClass("selected");
      if ($(this).is('.top'))
        $(this).next().removeClass("selected");
      else
        $(this).prev().removeClass("selected");
    }
  });
  $("table.fanzub tr").dblclick(function() {
    dblclick = !dblclick;
    $("table.fanzub input[type=checkbox]").attr("checked",dblclick);
    if (dblclick)
      $("table.fanzub tr").addClass("selected");
    else
      $("table.fanzub tr").removeClass("selected");
  });
});
$("#nzb").submit(function () {
  if ($('table.fanzub input[type=checkbox][checked]').size() == 0)
  {
    alert('Please select at least one file');
    return false;
  }
});

/* ]]> */
</script>

<!-- Quantcast Tag -->
<script type="text/javascript">
var _qevents = _qevents || [];

(function() {
var elem = document.createElement('script');
elem.src = (document.location.protocol == "https:" ? "https://secure" : "http://edge") + ".quantserve.com/quant.js";
elem.async = true;
elem.type = "text/javascript";
var scpt = document.getElementsByTagName('script')[0];
scpt.parentNode.insertBefore(elem, scpt);
})();

_qevents.push({
qacct:"p-65fDiBy2lVMDM"
});
</script>

<noscript>
<div style="display:none;">
<img src="//pixel.quantserve.com/pixel/p-65fDiBy2lVMDM.gif" border="0" height="1" width="1" alt="Quantcast"/>
</div>
</noscript>
<!-- End Quantcast tag -->
</body>
</html>