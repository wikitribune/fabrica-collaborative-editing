jQuery(document).ready(function($) {

	// Increase heartbeat to 5 seconds
	wp.heartbeat.interval('fast');

	// Send post ID with first tick
	wp.heartbeat.enqueue('fce', { 'post_id' : jQuery('#post_ID').val() }, true);

	// Listen for Heartbeat repsonses
	$(document).on('heartbeat-tick.fce', function(e, data) {

		// Re-send the post ID with each subsequent tick
		wp.heartbeat.enqueue('fce', { 'post_id' : jQuery('#post_ID').val() }, true);

		// [DEBUG] Log response
		console.log(data);

		// Check if revision has been update
		// [TODO] Force conflict resolution immediately
		/* if (data.fce.last_revision_id != $('#fce_last_revision_id').val()) {
			console.log('A new revision has been published while you have been editing.');
		} */
	});

	// Don't show a warning when clicking the Resolve button
	$('#resolve-edit-conflict').click(function() { $(window).off('beforeunload'); });

	// Activate diff tabs
	$('.fce-diff-tab').click(function(){
		if ($(this).hasClass('fce-diff-tab-visual')) {
			$(this).closest('.fce-diff-pair').find('.fce-diff-tab').removeClass('fce-diff-tab--active');
			$(this).addClass('fce-diff-tab--active');
			$(this).closest('.fce-diff-pair').find('.fce-diff').removeClass('fce-diff--active');
			$(this).closest('.fce-diff-pair').find('.fce-diff-visual').addClass('fce-diff--active');
		} else if ($(this).hasClass('fce-diff-tab-text')) {
			$(this).closest('.fce-diff-pair').find('.fce-diff-tab').removeClass('fce-diff-tab--active');
			$(this).addClass('fce-diff-tab--active');
			$(this).closest('.fce-diff-pair').find('.fce-diff').removeClass('fce-diff--active');
			$(this).closest('.fce-diff-pair').find('.fce-diff-text').addClass('fce-diff--active');
		}
	});
});
