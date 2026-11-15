import 'dart:io';

import 'package:flutter/foundation.dart';

import '../storage/secure_store.dart';

class AppConfig {
  const AppConfig._();

  static const _apiBaseUrlKey = 'nexo.api_base_url';
  static const testApiHost = '2.188.205.35';
  static const testApiPort = 18100;
  static const testHttpEnabled = bool.fromEnvironment(
    'ALLOW_TEST_HTTP',
    defaultValue: false,
  );

  static const developmentApiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api/v1',
  );

  static String? _savedApiBaseUrl;

  static String get apiBaseUrl => _savedApiBaseUrl ?? developmentApiBaseUrl;

  static bool get hasConfiguredApiBaseUrl => _savedApiBaseUrl != null;

  static Future<bool> load(SecureStore store) async {
    _savedApiBaseUrl = null;
    final saved = await store.read(_apiBaseUrlKey);
    final normalized = saved == null ? null : normalizeApiBaseUrl(saved);
    if (normalized != null && isAllowedApiBaseUrl(normalized)) {
      _savedApiBaseUrl = normalized;
    }
    return hasConfiguredApiBaseUrl;
  }

  static Future<bool> saveApiBaseUrl(
      SecureStore store, String value) async {
    final normalized = normalizeApiBaseUrl(value);
    if (normalized == null || !isAllowedApiBaseUrl(normalized)) {
      throw const FormatException('Invalid API base URL.');
    }
    final changed = _savedApiBaseUrl != normalized;
    await store.write(_apiBaseUrlKey, normalized);
    _savedApiBaseUrl = normalized;
    return changed;
  }

  static String? normalizeApiBaseUrl(String value) {
    final trimmed = value.trim();
    final uri = Uri.tryParse(trimmed);
    if (uri == null || uri.host.isEmpty || uri.userInfo.isNotEmpty ||
        uri.hasQuery || uri.hasFragment || uri.path.contains('..')) {
      return null;
    }
    final path = uri.path.replaceFirst(RegExp(r'/+$'), '');
    final apiPath = path.isEmpty
        ? '/api/v1'
        : path.endsWith('/api/v1')
            ? path
            : path.endsWith('/api')
                ? '$path/v1'
                : '$path/api/v1';
    return uri.replace(path: apiPath).toString();
  }

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
        _isDevelopmentHttpHost(uri.host, uri.port);
  }

  static bool _isDevelopmentHttpHost(String host, int port) {
    if (const {'10.0.2.2', '127.0.0.1', 'localhost'}.contains(host) ||
        (host == testApiHost && port == testApiPort)) {
      return true;
    }
    final address = InternetAddress.tryParse(host);
    if (address?.type != InternetAddressType.IPv4) return false;
    final octets = address!.address.split('.').map(int.parse).toList();
    return octets[0] == 10 ||
        (octets[0] == 172 && octets[1] >= 16 && octets[1] <= 31) ||
        (octets[0] == 192 && octets[1] == 168);
  }
}
