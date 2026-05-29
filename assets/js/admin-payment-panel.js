(function() {
	document.addEventListener('DOMContentLoaded', function() {
		var cb = document.querySelector('input[name="slp_payment[enabled]"]');
		var fields = cb ? cb.closest('fieldset').querySelectorAll('tr:not(:first-child)') : [];
		var modeInputs = document.querySelectorAll('input[name="slp_payment[amount_mode]"]');

		function toggleAmountMode() {
			if (cb && !cb.checked) return;
			var checked = document.querySelector('input[name="slp_payment[amount_mode]"]:checked');
			var mode = checked ? checked.value : 'fixed';
			document.querySelectorAll('.slp-amount-fixed-row').forEach(function(row) {
				row.style.display = mode === 'fixed' ? '' : 'none';
			});
			document.querySelectorAll('.slp-amount-field-row').forEach(function(row) {
				row.style.display = mode === 'field_mapping' ? '' : 'none';
			});
			document.querySelectorAll('.slp-amount-suggested-row').forEach(function(row) {
				row.style.display = mode === 'user_input' ? '' : 'none';
			});
		}

		function toggle() {
			if (!cb) return;
			fields.forEach(function(tr) {
				tr.style.display = cb.checked ? '' : 'none';
			});
			toggleAmountMode();
		}

		if (cb) {
			toggle();
			cb.addEventListener('change', toggle);
		}
		modeInputs.forEach(function(input) {
			input.addEventListener('change', toggleAmountMode);
		});
		toggleAmountMode();
	});
})();
