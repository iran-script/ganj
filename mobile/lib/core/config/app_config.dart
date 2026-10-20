import 'package:flutter/foundation.dart';

class AppConfig {
  const AppConfig._();

  static const developmentTestApiBaseUrl = 'http://2.188.205.35:18100/api/v1';

  static const apiBuildVariant = String.fromEnvironment(
    'API_BUILD_VARIANT',
    defaultValue: kDebugMode ? 'test' : 'production',
  );

  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue:
        kDebugMode ? developmentTestApiBaseUrl : 'http://10.0.2.2:8000/api/v1',
  );

  /// Public OSM tiles are for prototype use; production should configure a
  /// provider approved for NEXO's commercial traffic and supported regions.
  static const mapTileUrl = String.fromEnvironment(
    'MAP_TILE_URL',
    defaultValue: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
  );
  static const mapTileAttribution = String.fromEnvironment(
    'MAP_TILE_ATTRIBUTION',
    defaultValue: '© OpenStreetMap contributors',
  );
  static const mapTileAttributionUrl = String.fromEnvironment(
    'MAP_TILE_ATTRIBUTION_URL',
    defaultValue: 'https://www.openstreetmap.org/copyright',
  );

  static String get diagnosticApiBaseUrl {
    return sanitizedApiBaseUrl(apiBaseUrl);
  }

  static String sanitizedApiBaseUrl(String value) {
    final uri = Uri.tryParse(value);
    if (uri == null || uri.host.isEmpty) return '<invalid-api-url>';
    final port = uri.hasPort ? ':${uri.port}' : '';
    return '${uri.scheme}://${uri.host}$port${uri.path}';
  }

  static bool isAllowedApiBaseUrl(String value) {
    final uri = Uri.tryParse(value);
    if (uri == null ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment) {
      return false;
    }
    if (uri.scheme == 'https') return true;
    return apiBuildVariant == 'test' &&
        uri.scheme == 'http' &&
        uri.host == '2.188.205.35' &&
        uri.port == 18100 &&
        uri.path == '/api/v1';
  }
}
