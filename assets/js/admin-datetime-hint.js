(function () {
	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.wpd-datetime-hint').forEach(function (hint) {
			var target = document.getElementById(hint.dataset.target);
			var time = hint.dataset.time || '';
			if (!target || !time) { return; }

			// One-shot: apply the template time on the first change that
			// leaves the time portion at HH:MM = 00:00 (which is what the
			// browser's date picker produces when the user picks a date
			// without touching the time part). We stop after applying so a
			// user who deliberately clears the time isn't fought.
			var applied = false;
			target.addEventListener('change', function () {
				if (applied || !target.value) { return; }
				var m = target.value.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})$/);
				if (m && m[2] === '00:00') {
					target.value = m[1] + 'T' + time;
					applied = true;
				}
			});
		});
	});
})();
