<div id="searchbox">
	<form method="get" action="/">
	<p>
		<input type="text" name="q" value="{$query|escape}" />
		<select name="cat">
			<option value=""{$cat[all]|}>All</option>
			<option value="anime"{$cat[anime]|}>Anime</option>
			<option value="drama"{$cat[drama]|}>Drama</option>
			<option value="music"{$cat[music]|}>Music</option>
			<option value="raws"{$cat[raws]|}>Raws</option>
			<option value="hentai"{$cat[hentai]|}>Hentai</option>
			<option value="hmanga"{$cat[hmanga]|}>Hmanga</option>
			<option value="games"{$cat[games]|}>Games</option>
			<option value="dvd"{$cat[dvd]|}>DVD</option>
		</select>
		<button type="submit">Search</button>
		{$rss|}
	</p>
	</form>
</div>
