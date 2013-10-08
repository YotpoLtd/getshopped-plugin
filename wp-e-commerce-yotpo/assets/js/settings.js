jQuery(document).ready(function () {
	
	console.log('alon');

	var hide_other_explanation = function(duration) {
		if(jQuery('#yotpo_settings_form .yotpo-widget-location').val() == 'other') {
			jQuery('#yotpo_settings_form .yotpo-widget-location-other-explain').show(duration);
		}
		else {
			jQuery('#yotpo_settings_form .yotpo-widget-location-other-explain').hide(duration);
		}	
	};
	
	hide_other_explanation(0);
	jQuery('#yotpo_settings_form .yotpo-widget-location').change(function() {
		hide_other_explanation(1000);
	});
});

