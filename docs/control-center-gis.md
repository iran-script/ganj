# Control Center GIS

OpenLayers 10.11.0 is the only web mapping library. The map renderer is loaded by
next/dynamic with SSR disabled, only when a map is displayed. The dashboard does
not import OpenLayers. GPS inspectors load it when the operator opens the map.

## Coordinates and radius

Domain data uses EPSG:4326 objects with latitude and longitude. Only projection.ts
converts to/from OpenLayers [longitude, latitude] and EPSG:3857. Invalid data is
never rendered. Valid polar coordinates beyond 85.05112878 degrees remain editable
manually; the map warns that they are outside Mercator coverage.

Radius uses ol/geom/Polygon.circular: 96 vertices at a great-circle distance in
meters on the WGS84 mean-radius sphere, projected into EPSG:3857. It is an authoring
visualization, not the authoritative PostGIS geography acceptance calculation.
Antimeridian rings are unwrapped before projection. Circles crossing Mercator's
polar limit are omitted; manual coordinates remain canonical.

## Sources and layers

map-config.ts owns the basemap factory. Default: OpenStreetMap with visible
attribution, normal browser caching, no bulk download/prefetch or offline caching.
NEXT_PUBLIC_MAP_TILE_URL can specify a permitted XYZ service; set
NEXT_PUBLIC_MAP_ATTRIBUTION to its required attribution. No keys are committed.
Future municipal/WMTS/intranet sources should replace this factory.

Vector sources are separate for saved-location markers and objective radius.
The controller owns all OpenLayers instances, subscriptions and interactions;
React retains domain values only. Unmount disconnects resize observation,
unsubscribes events, detaches the target and disposes the map. Future authorized
team/player/route layers can be added without creating another map initializer.

## Authoring and API

Location Bank reuses GET/POST/PATCH /content/locations. The canonical backend model
is Checkpoint with POINT geometry; there are no new columns or endpoints.
Lists and markers share selection/filter state; invalid rows remain visible with
a warning. Forms support direct input, click and drag; tile errors never clear
form data. Read-only GPS nodes disable map editing.

The saved-location picker defaults to active records and may display inactive
records as disabled choices. Selection copies latitude, longitude, radius_m,
accuracy_m and dwell_seconds into the node. No location ID or mutable dependency
is introduced. The existing backend publishes immutable ScenarioVersion snapshots.

QR installation GIS metadata — future backend extension. Current QR bank data has
a textual installation description, not coordinates. No QR secrets are fetched
for GIS. Live Control's current control-room projection has presence timestamps,
not measured player/team coordinates, so its GIS state is unavailable.

## Verification limits

Tests exercise geometry, event conversions, state synchronization, form payloads,
snapshot independence and failure fallback without pixel/canvas snapshots.
Production authentication/browser acceptance is separate and requires a safe
operator session. This slice does not contact or change production, seed records,
perform realtime tracking, or add geocoding, AR or Phase 3.0.
