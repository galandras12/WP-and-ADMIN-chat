/**
 * Admin chat – logó kiválasztása a médiatárból a szoba szerkesztésekor.
 */
jQuery(function ($) {
	var frame;
	var $field = $('.iwc-logo-field');
	var $id = $('#iwc-logo-id');
	var $preview = $field.find('.iwc-logo-preview');
	var $remove = $field.find('.iwc-logo-remove');

	$field.on('click', '.iwc-logo-select', function (e) {
		e.preventDefault();
		if (!frame) {
			frame = wp.media({
				title: IWC_ADMIN.frameTitle,
				button: { text: IWC_ADMIN.frameButton },
				library: { type: 'image' },
				multiple: false
			});
			frame.on('select', function () {
				var att = frame.state().get('selection').first().toJSON();
				var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
				$id.val(att.id);
				$preview.attr('src', url).prop('hidden', false);
				$remove.prop('hidden', false);
			});
		}
		frame.open();
	});

	$remove.on('click', function (e) {
		e.preventDefault();
		$id.val('0');
		$preview.attr('src', '').prop('hidden', true);
		$remove.prop('hidden', true);
	});
});
