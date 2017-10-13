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
});
