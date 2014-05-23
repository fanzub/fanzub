<h1>FAQ</h1>
<dl>
	
<dt>What is Fanzub?</dt>
<dd>Fanzub is a Usenet search engine for anime &amp; Japanese media related newsgroups.</dd>

<dt>What is Usenet?</dt>
<dd><a href="http://en.wikipedia.org/wiki/Usenet">Usenet</a> is a worldwide distributed Internet discussion system. Think of it as a network of forum servers, where if somebody posts a message to a newsgroup on one server it's automatically distributed to all other servers in the network. While Usenet was originally intended for text discussions, binary files now make up the bulk of Usenet traffic.</dd>

<dt>How do I download?</dt>
<dd>Fanzub does not host any downloads, only NZB files. To download you will need Usenet client capable of handling NZB files and access to a Usenet server. See the <a href="/help/guide">Usenet Guide</a> for more information.</dd>

<dt>What are NZB files?</dt>
<dd>NZB files is a file format originally developed by the now invite-only website Newzbin.com. NZB files make it easier to find downloads on Usenet, and from the viewpoint of the downloader NZB files function much like torrent files do for BitTorrent.</dd>

<dt>What are PAR/PAR2 files?</dt>
<dd>Usenet wasn't designed for distributing large binary files. As such files need to be split up in many little parts. If any of these parts is missing, you would be unable to complete your download. This where PAR/PAR2 &quot;parity&quot; files come in - they work as magic glue that can fix broken or missing parts of your download as long as you have enough of them. A popular PAR/PAR2 utility for Windows is <a href="http://www.quickpar.org.uk/">QuickPar</a>.</dt>

<dt>What are RAR files?</dt>
<dd>It is generally not accepted to post huge files as one article on Usenet, so files are frequently split up using the file compression utility <a href="http://www.rarlab.com/">RAR</a>. Note that for files that are already compressed (such as MKV/MP4/AVI) split files are generally a better option as not much is gained by RAR compression.</dt>

<dt>What are .001, .002, .003 (etc) files?</dt>
<dd>These files are called &quot;split&quot; files. They're simply evenly sized chunks of the original file. When you verify the download using PAR2 the files should be joined automatically. If not, see <a href="http://www.freebyte.com/hjsplit/">this site</a> for file joining tools.</dd>

<dt>What is the parts percentage in each listing?</dt>
<dd>Each Usenet post is split up in countless parts. In the listing the completion ratio (parts found / parts total) is shown as a percentage. If the post is incomplete the percentage will displayed in red.</dd>

<dt>What is the number with a "d" at the end of the listing?</dt>
<dd>As terabytes of new binary files are posted to Usenet each day, it is not feasible for Usenet providers to keep posts indefinitely. For this reason Usenet providers will purge posts that are X days old. The number of days a Usenet provider keeps posts is called &quot;retention&quot;. The number with at the end of each listing represents the age of the post in <b>d</b>ays, so that you can easily determine if the post may or may not be already gone from the Usenet server you use (see the website of your Usenet provider for retention information).</dd>

<dt>Can you please add (insert desired content here)?</dt>
<dd>Fanzub only indexes Usenet and has no control over its content.</dd>

<dt>Why can't I find certain posts?</dt>
<dd>The following kind of posts are not (yet) indexed or displayed:
<ul>
	<li>Posts with only 1 file (most of these are spam)</li>
	<li>Posts smaller than 1 megabyte (most of these are spam or discussion)</li>
	<li>Posts that are less than 80% complete (PAR/PAR2 files usually cover less than 20%)</li>
	<li>Posts that were recently found but are still incomplete (as they're probably still being uploaded)</li>
</ul>
</dd>

<dt>A post is in the wrong category?</dt>
<dd>Posts are automatically put into categories based on the newsgroup they were posted in. See also next question.</dd>

<dt>Which newsgroups do the categories represent?</dt>
<dd> The categories represent the following newsgroups:
<ul>
	<li><b>Anime</b>
		<ul>
			<li>alt.binaries.anime</li>
			<li>alt.binaries.multimedia.anime</li>
			<li>alt.binaries.multimedia.anime.repost</li>
			<li>alt.binaries.multimedia.anime.highspeed</li>
		</ul>
	</li>
	<li><b>Drama</b>
		<ul>
			<li>alt.binaries.multimedia.japanese</li>
			<li>alt.binaries.multimedia.japanese.repost</li>
		</ul>
	</li>		
	<li><b>Music</b>
		<ul>
			<li>alt.binaries.sounds.anime</li>
			<li>alt.binaries.sounds.jpop</li>
		</ul>
	</li>		
	<li><b>Raws</b>
		<ul>
			<li>alt.binaries.multimedia.anime.raws</li>
		</ul>
	</li>		
	<li><b>Hentai</b>
		<ul>
			<li>alt.binaries.multimedia.erotica.anime</li>
		</ul>
	</li>		
	<li><b>Hmanga</b>
		<ul>
			<li>alt.binaries.pictures.erotica.anime</li>
		</ul>
	</li>		
	<li><b>Games</b>
		<ul>
			<li>alt.binaries.games.anime</li>
		</ul>
	</li>		
	<li><b>DVD</b>
		<ul>
			<li>alt.binaries.dvd.anime</li>
			<li>alt.binaries.dvd.anime.repost</li>
		</ul>
	</li>		
</ul></dd>

<dt>How can I browse recent posts?</dt>
<dd>Fanzub is intended to be used as a search engine. However to view the recent most 200 posts, simply click &quot;Search&quot; without typing  anything.</dd>

<dt>Fanzub filters the subject line - how do I see the original subject?</dt>
<dd>Just hover your mouse over the link or click <i>Details</i>.</dd>

<dt>How often does Fanzub update its database?</dt>
<dd>New headers are downloaded from all indexed newsgroups every 5 minutes (from 4 different Usenet providers).</dd>

<dt>Can I do boolean searches?</dt>
<dd>
	Yes. Fanzub is using the <i>&quot;Extended&quot;</i> matching mode of <a target="_blank" href="http://www.sphinxsearch.com/">Sphinx Search</a>. See the Sphinx <a target="_blank" href="http://sphinxsearch.com/docs/manual-0.9.9.html#extended-syntax">documentation</a> for more information. Not all options mentioned on that page work, but the most important ones (like <b>|</b> as &quot;or&quot; operator and <b>-</b> for exclusions) should work.
	If you want to search specific fields you can use the following field names: <b>@subject</b>, <b>@poster</b> and <b>@files</b>. The <i>&quot;files&quot;</i> field contains a list of filetypes, such as also displayed in the <i>&quot;Files&quot;</i> list for each post.
</dd>

<dt>How can I download multiple files as one NZB file?</dt>
<dd>
	<p>
		Simply click on each row for each file you want to download and then click the <i>&quot;Get NZB&quot;</i> button on the bottom of the page.
		Selected files are indicated by an orange background color. To deselect, simply click the row again.
	<p>
	<p>
		You can select a row of files by holding down the mouse button and dragging accross the files you want to select.
		This mechanism alternates between selecting and deselecting with each mouse up/down event, so try it again if nothing happens when trying to select files this way.
	</p>
	<p>
		To select all files on a page double-click the list of files. To deselect all files simply double-click again.
		As this is the easiest way to select a long list of files it is easiest to use the double-click technique with a refined search query to download lots of files at once.
	</p>
</dd>

<dt>Is it possible for RSS feeds to return more than 50 items?</dt>
<dd>Yes, just add the <b>max</b> parameter to the URL, like this: <a target="_blank" href="http://fanzub.com/rss?cat=anime&amp;max=200">fanzub.com/rss?cat=anime&amp;max=200</a></dd>

<dt>Is there something like an API to retrieve infromation from the Fanzub database?</dt>
<dd>
	<p>
		Yes, just replace the <i>&quot;rss&quot;</i> bit from the RSS URL with <i>&quot;export&quot;</i>, like this: <a target="_blank" href="http://fanzub.com/export?cat=anime">fanzub.com/export?cat=anime</a>.
		The <i>&quot;export&quot;</i> feed accepts the same arguments as the RSS feed (including the <i>max</i> parameter).
		By default the <i>&quot;export&quot;</i> feed will return an <a target="_blank" href="http://en.wikipedia.org/wiki/Json">JSON</a> encoded array of posts. You can specify the <b>format=<i>serial</i></b> parameter to have the feed return a <a target="_blank" href="http://php.net/unserialize">PHP</a> serialized array instead.
	</p>
	<p>
		Some posts are ommitted from the list of posts by default because they're either considered spam or are presumed to be still being uploaded. In order to retrieve the whole list of detected posts (including those not visible on the site) add the <b>filter=<i>0</i></b> parameter.
		When you use this parameter the <i>&quot;category&quot;</i> field will be <i>NULL</i> for those posts that are not (yet) listed on the site. If a post is not listed because it is considered spam, the <i>&quot;hidden&quot;</i> flag will be set to <i>true</i>.
	</p>
</dd>

<dt>Can I access the site using SSL (HTTPS)?</dt>
<dd>Yes, just acccess the site using <a href="https://fanzub.com/">https://fanzub.com</a>.</dd>

</dl>