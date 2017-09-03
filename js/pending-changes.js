(function($) {
	$(function() {
		// Get published revision index in revisions data array
		var revisionData = _wpRevisionsSettings.revisionData,
			publishedIndex = revisionData.length - 1;
		for (var i = publishedIndex; i >= 0; i--) {
			revision = revisionData[i];
			if (revision.current) {
				publishedIndex = i;
				break;
			}
		}

		// Mark the published revision visually
		$('.revisions-tickmarks div:nth-child(' + (publishedIndex + 1) + ')').css({
			borderLeft: '3px solid crimson'
		});
		var publishedPosition = (publishedIndex + 1) / revisionData.length * 100,
		$pendingChangesTickmarks = $('<span class="fc-current-revision-tickmark">');
		$pendingChangesTickmarks.css({
			position: 'absolute',
			height: '100%',
			'-webkit-box-sizing': 'border-box',
			'-moz-box-sizing': 'border-box',
			boxSizing: 'border-box',
			display: 'block',
			left: publishedPosition + '%',
			width: (100 - publishedPosition) + '%',
			border: 'none',
			backgroundColor: 'lightgray',
		});
		$('.revisions-tickmarks').prepend($pendingChangesTickmarks);
	});
})(jQuery);
