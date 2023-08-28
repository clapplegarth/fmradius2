function showIdeas()
{
	window.alert("Try searching for a frequency like 96.7. Or a callsign like KQED. Or a service type such as FS (for backup transmitters).");
}

function create_random_color(seed) {
	var myrng = new Math.seedrandom(seed);
	Math.seedrandom(seed);
	let r = Math.floor(myrng() * 192 + 64);
	let g = Math.floor(myrng() * 192 + 64);
	let b = Math.floor(myrng() * 192 + 64);
	// Return color in hex format
	return "#" + r.toString(16) + g.toString(16) + b.toString(16);
}

// Create a unique color for each feature based on its properties -> application_id
function color_feature_by_application_id(feature) {

	let color = create_random_color(feature.properties['application-id'] ? feature.properties['application-id'].toString() : '');
	console.log(feature, color);
	return {color: color};
}

function create_bootstrap_alert(message, alert_type = "warning") {
	// Create alert
	let alert = document.createElement("div");
	alert.classList.add("alert", "alert-" + alert_type.toLowerCase(), "alert-dismissible", "fade", "show")
	alert.setAttribute("role", "alert");
	alert.innerHTML = message;
	document.getElementById("alerts").appendChild(alert);

	// Add dismiss button
	alert.appendChild(document.createElement("button"));
	alert.lastChild.setAttribute("type", "button");
	alert.lastChild.classList.add("close");
	alert.lastChild.setAttribute("data-dismiss", "alert");
	alert.lastChild.setAttribute("aria-label", "Close");
	alert.lastChild.innerHTML = "<span aria-hidden=\"true\">&times;</span>";

	// Set up timer to close alert after 5 seconds
	setTimeout(function() {
		alert.classList.remove("show");
		setTimeout(function() {
			alert.remove();
		}, 1000);
	}, 5000);

	return alert;
}

function submitForm(e) {
	e.preventDefault();
	let formData = new FormData(this);
	let url = "get/search.php";
	let sep = '?';
	// parameterize form and add to url
	for (let pair of formData.entries()) {
		url += sep + `${pair[0]}=${pair[1]}`;
		if (sep == '?') sep = '&';
	}
	fetch(url, {method: "GET"})
	.then(response => response.json())
	.then(data => {
		console.log(data);

		if ('err' in data) {
			create_bootstrap_alert(data['err'], "danger");
			return;
		}
		
		if ('msg' in data) {
			create_bootstrap_alert(data['msg'], "info");
		}


		// Add new points and contours to map
		if ((!('points' in data)) || (data['points'].length == 0)) {
			create_bootstrap_alert("No points loaded. Try a different search.");
		}
		else {
			mapFeatureGroup.clearLayers();

			// Load contours first for z-index reasons (points should be on top)
			if (data.hasOwnProperty('contours') && data['contours'] !== null) {
				let newContourLayer = L.geoJSON(data['contours'], {
					style: color_feature_by_application_id
				});
				mapFeatureGroup.addLayer(newContourLayer);
				console.log("Loaded", data['contours'].length, "contours");
			}

			let newPointsLayer = L.geoJSON(data['points'], {
				style: color_feature_by_application_id,
				onEachFeature: function(feature, layer) {
					// shorthand for string interpolation
					let fp = feature.properties;
					layer.bindPopup(`<div><sup>${fp.service_type}</sup> ${fp.frequency} ${fp.callsign}</div>
						<div>${fp.city}, ${fp.state}, ${fp.country} (#${fp.application_id})</div>`);
				}
			});
			
			newPointsLayer.setStyle((ft) => create_random_color(ft.properties.application_id));

			mapFeatureGroup.addLayer(newPointsLayer);
			console.log("Loaded", data['points']['features'].length, "points");



			// We can change the map view if:
			// 1. withinMapView is 0, meaning we had to search outside the current bounds
			//    OR
			// 2. the current bounds completely contain the desired new bounds
			if ((data['withinMapView'] == 0) || (map.getBounds().contains(mapFeatureGroup.getBounds())) )
			{
				map.fitBounds(mapFeatureGroup.getBounds());
			}

		}
	});
}



function setupMap(e)
{
	// declared externally
	map = L.map('map', {editable: true, zoomControl: false, minZoom: 5, maxZoom: 15}).setView([40, -100], 5);
	L.control.zoom({position: 'topright'}).addTo(map);
	
	L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
		attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
	}).addTo(map);
	console.log("howdy");
	
	mapFeatureGroup.addTo(map);

	// When bounds change, update hidden fields to reflect new bounds
	map.on('moveend', function(e) {
		var bounds = map.getBounds();
		document.getElementById("minLat").value = bounds.getSouth();
		document.getElementById("maxLat").value = bounds.getNorth();
		document.getElementById("minLon").value = bounds.getWest();
		document.getElementById("maxLon").value = bounds.getEast();
	 });

	// Fire moveend event now to initialize hidden fields
	map.fire('moveend');

	console.log(document.getElementById("searchForm"));

	// Handle form submission to search.php
	document.getElementById("searchForm").addEventListener("submit", submitForm);
}


// Reset radio buttons when user clicks on map

var map = null;
var mapFeatureGroup = L.featureGroup();

addEventListener("load", function(e) { setupMap(e); });

