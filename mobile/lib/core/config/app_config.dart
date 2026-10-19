import 'package:flutter/foundation.dart';

class AppConfig {
  const AppConfig._();

  static const testApiHost = '2.188.205.35';
  static const testApiPort = 18100;
  static const testHttpEnabled = bool.fromEnvironment(
    'ALLOW_TEST_HTTP',
    defaultValue: false,
  );

  static const apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
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
    return (kDebugMode || testHttpEnabled) &&
        uri.scheme == 'http' &&
        (const {'10.0.2.2', '127.0.0.1', 'localhost'}.contains(uri.host) ||
            (uri.host == testApiHost && uri.port == testApiPort));
  }
}
