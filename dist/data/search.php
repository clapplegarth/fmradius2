<?php
header('Content-Type: application/json');
include("../scripts/config.php");

// Generates a GeoJSON of the points returned in $data.
// Uses the specified properties as the keys to use to grab lat/lon.
// Removes the lat/lon keys from the properties.
function generate_point_geojson(array &$data, string $lat_key, string $lon_key)
{
	$out = [];
	foreach($data as $item)
	{
		if (isset($item[$lat_key]) && isset($item[$lon_key]))
		{
			[$lat, $lon] = [$item[$lat_key], $item[$lon_key]];
			// Remove keys from properties
			unset($item[$lon_key]);
			unset($item[$lat_key]);
			unset($item["service_contour"]);
			array_push($out, [
				"type" => "Feature",
				"geometry" => [
					"type" => "Point",
					"coordinates" => [$lon, $lat]], // GeoJSON in lon,lat order
				"properties" => $item
			]);
		}
	}
	return json_encode(["type" => "FeatureCollection", "features" => $out], JSON_PRETTY_PRINT);
}

// Generates a GeoJSON of the contours.
// Only provides application_id in the properties.
function generate_contour_geojson(array &$data, string $contour_key = 'service_contour')
{
	$out = [];
	$i = 0;
	foreach($data as $item)
	{
		if (isset($item[$contour_key]))
		{
			$contour = $item[$contour_key];
			unset($item[$contour_key]);
			array_push($out, [
				"type" => "Feature",
				"geometry" => [
					"type" => "Polygon",
					"coordinates" => array(json_decode($contour)) ],
				"properties" => ["application-id" => $item['application_id']]
			]);
		}
		if ($i >= QUERY_MAX_CONTOURS)
			break;
	}
	return json_encode(["type" => "FeatureCollection", "features" => $out], JSON_PRETTY_PRINT);
}

// Builds and executes the query based on the tokens parsed from the query
// and the selected form settings.
// Returns: [data, sql, params]
function perform_query(PDO $dbconn, array $collected_tokens, bool $use_bounds_parameters = true) : array
{
	$sql = "SELECT `application_id`, `frequency`, `callsign`, `service_type`, `city`, `state`, `country`, `transmitter_site_lat`, `transmitter_site_lon`, `service_contour` FROM fmradius.fcc WHERE (`transmitter_site_lat` IS NOT NULL) AND (`transmitter_site_lon` IS NOT NULL) ";
	$params = [];

	if ($use_bounds_parameters)
	{
		$sql .= "AND `transmitter_site_lat` <= :max_lat AND `transmitter_site_lat` >= :min_lat AND `transmitter_site_lon` <= :max_lon AND `transmitter_site_lon` >= :min_lon ";
		$params[":min_lat"] = $_GET["minLat"];
		$params[":max_lat"] = $_GET["maxLat"];
		$params[":min_lon"] = $_GET["minLon"];
		$params[":max_lon"] = $_GET["maxLon"];
	}

	// Add tokens to query
	// Simple tokens (just check if the eponymous database column matches)
	$standard_tokens = ["frequency", "state"];
	foreach($standard_tokens as $token_name)
	{
		if (isset($collected_tokens[$token_name]))
		{
			$sql .= "AND " . $token_name . " = :" . $token_name . " ";
			$params[":" . $token_name] = $collected_tokens[$token_name];
		}
	}

	// Slightly more complex token operations
	if (isset($collected_tokens['service_type']))
	{
		if ($collected_tokens['service_type'] == "FM|FL") {
			$sql .= "AND (service_type = 'FM' OR service_type = 'FL') ";
		}
		else
		{
			$sql .= "AND (service_type = :service_type) ";
			$params[":service_type"] = $collected_tokens['service_type'];
		}
	}
	if (isset($collected_tokens['callsign_like']))
	{
		$sql .= "AND (callsign LIKE :callsign_like) ";
		$params[":callsign_like"] = $collected_tokens['callsign_like'];
	}
	if (isset($collected_tokens['text']))
	{
		$sql .= "AND (callsign LIKE :text OR city LIKE :text OR country LIKE :text) ";
		$params[":text"] = "%" . $collected_tokens['text'] . "%";
	}

	$sql .= " LIMIT " . QUERY_MAX_POINTS . ";";
	
	$stmt = $dbconn->prepare($sql);
	$stmt->execute($params);
	
	$stmt->setFetchMode(PDO::FETCH_ASSOC);
	return [$stmt->fetchAll(), $sql, $params];
}


$dbconn = new PDO(DB_DRIVER . ":host=" . DB_HOST . ";dbname=" . DB_DBNAME, DB_USERNAME, DB_PASSWORD);

// parse search
$collected_tokens = [];
$unknown_tokens = [];
$valid_state_abbreviations = ["AB", "AK", "AL", "AR", "AS", "AZ", "BC", "BN", "BW", "CA", "CH", "CI", "CO", "CT", "DC", "DE", "FL", "GA", "GU", "HI", "IA", "ID", "IL", "IN", "KS", "KY", "LA", "MA", "MB", "MD", "ME", "MI", "MN", "MO", "MP", "MS", "MT", "NB", "NC", "ND", "NE", "NH", "NJ", "NL", "NM", "NS", "NT", "NV", "NY", "OH", "OK", "ON", "OR", "PA", "PE", "PR", "QC", "RI", "SC", "SD", "SK", "SO", "TA", "TN", "TX", "UT", "VA", "VI", "VT", "WA", "WI", "WV", "WY", "YT"];

if (isset($_GET["q"]) && ($_GET["q"] != "") && ($_GET["q"] != "*"))
{
	$tokens = explode(" ", $_GET["q"]);
	foreach($tokens as $token) {
		if (is_numeric($token)) {
			$token = floatval($token);
			if ($token < 88.1 || $token > 107.9) {
				array_push($unknown_tokens, $token);
				continue;
			}
			else {
				$collected_tokens['frequency'] = $token;
			}
		}
		// State abbreviation
		elseif (in_array($token, $valid_state_abbreviations))
		{
			$collected_tokens['state'] = $token;
		}
		// Service Type
		elseif (preg_match("/^F[ABMRSX]$/", $token))
		{
			$collected_tokens['service_type'] = $token;
		}
		// Callsign (or portion thereof)
		// elseif (preg_match("/^[WK][A-Z]{0-3}/", $token))
		// {
		// 	$collected_tokens['callsign_like'] = $token . "%";
		// }
		else
		{
			$collected_tokens['text'] = $token;
		}
	}
}

// Override service type if specified in checkbox
if ($_GET["fmonly"])
	$collected_tokens['service_type'] = "FM|FL";

// TODO (possible optimization)
// Create a preliminary query to determine if the number of matches is greater than $threshold.
// If so, we should not retrieve the service contour data.

$within_map_view = ($_GET["bounds"] != "any");

[$res, $sql, $params] = perform_query($dbconn, $collected_tokens, $within_map_view);
$msg = "";
$err = "";

// Handle no results by either expanding boundaries if possible or returning an error.
if (count($res) == 0)
{
	if ($_GET["bounds"] == "prefer") {
		$msg .= "No results within bounds, searching nation-wide... ";
		$within_map_view = false;
		[$res, $sql, $params] = perform_query($dbconn, $collected_tokens, $within_map_view);
		if (count($res) == 0) {
			$err .= "No results found (nation-wide search). ";
		}
	}
	else {
		$err .= "No results found (only searched within this map view). ";
	}
}

if (count($res) == 300)
	$msg .= "Showing first " . QUERY_MAX_POINTS . " results. ";
else
	$msg .= count($res) . " results found. ";

if (count($res) > QUERY_MAX_CONTOURS)
	$msg .= "Too many results to show all service contours. ";

// Note any unparsable tokens
if ($unknown_tokens)
	$msg .= "Couldn't parse these search terms: " . implode(", ", $unknown_tokens) . ". ";

// debug
// array_push($msg, "Query: " . $sql . "; Params: " . json_encode($params) . "; Bounds: " . $_GET["bounds"] . "; Count: " . count($res));

if ($err)
	echo '{"err": "' . $err . '"}';
else
	echo '{"msg": "' . $msg . '", "withinMapView": ' . ($within_map_view ? 1 : 0) . ', "points": ' . generate_point_geojson($res, "transmitter_site_lat", "transmitter_site_lon") . ', "contours": ' . generate_contour_geojson($res, "service_contour") . '}';

?>