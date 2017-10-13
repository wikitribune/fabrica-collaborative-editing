jQuery(document).ready(function($) {

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
