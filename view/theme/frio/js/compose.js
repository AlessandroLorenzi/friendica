// @license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPLv3-or-later
$(function () {
	// Jot attachment live preview.
	setUpTextArea();
	setUpLocationFields();
});

function setUpTextArea() {
	let $textarea = $("textarea[name=body]");
	$textarea.linkPreview();
	$textarea.keyup(function () {
		const textlen = $(this).val().length;
		$("#character-counter").text(textlen);
	});
	$textarea.editor_autocomplete(baseurl + "/search/acl");
	$textarea.bbco_autocomplete("bbcode");
}

function setUpLocationFields() {
	let location_button = document.getElementById("profile-location");
	let location_input = document.getElementById("jot-location");

	if (!location_button || !location_input) {
		return;
	}

	updateLocationButtonDisplay(location_button, location_input);
	setUpLocationFieldsEvents(location_button, location_input);
}

function updateLocationButtonDisplay(location_button, location_input) {
	location_button.classList.remove("btn-primary");
	if (location_input.value) {
		location_button.disabled = false;
		location_button.classList.add("btn-primary");
		location_button.title = location_button.dataset.titleClear;
	} else if (!("geolocation" in navigator)) {
		location_button.disabled = true;
		location_button.title = location_button.dataset.titleUnavailable;
	} else if (location_button.disabled) {
		location_button.title = location_button.dataset.titleDisabled;
	} else {
		location_button.title = location_button.dataset.titleSet;
	}
}

function setUpLocationFieldsEvents(location_button, location_input){
	location_input.addEventListener("change", function () {
		updateLocationButtonDisplay(location_button, location_input);
	});

	location_input.addEventListener("keyup", function () {
		updateLocationButtonDisplay(location_button, location_input);
	});

	location_button.addEventListener("click", function () {
		locationButtonClickHandler(location_input, location_button);
	});
}

function locationButtonClickHandler(location_input, location_button) {
	if (location_input.value) {
		location_input.value = "";
		updateLocationButtonDisplay(location_button, location_input);
	} else if ("geolocation" in navigator) {
		navigator.geolocation.getCurrentPosition(
			function (position) {
				location_input.value = position.coords.latitude + ", " + position.coords.longitude;
				updateLocationButtonDisplay(location_button, location_input);
			},
			function (_error) {
				location_button.disabled = true;
				updateLocationButtonDisplay(location_button, location_input);
			},
		);
	}
}
// @license-end
