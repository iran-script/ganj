import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/config/app_config.dart';

void main() {
  test('API URL is explicit and diagnostics expose no credentials', () {
    expect(AppConfig.apiBaseUrl, 'https://api.example.test/api/v1');
    expect(AppConfig.diagnosticApiBaseUrl, 'https://api.example.test/api/v1');
    expect(AppConfig.sanitizedApiBaseUrl(''), '<invalid-api-url>');
    final safeUrl = AppConfig.sanitizedApiBaseUrl(
        'https://user:password@example.test/api/v1?token=secret');
    expect(safeUrl, 'https://example.test/api/v1');
    expect(safeUrl, isNot(contains('password')));
    expect(safeUrl, isNot(contains('secret')));
    expect(
        AppConfig.isAllowedApiBaseUrl(
            'https://user:password@example.test/api/v1?token=secret'),
        isFalse);
  });

  test('release and staging require HTTPS; development HTTP is loopback only', () {
    expect(AppConfig.isAllowedApiBaseUrl('https://api.example.test/api/v1'),
        isTrue);
    expect(
      AppConfig.isAllowedApiBaseUrlForVariant(
          'http://localhost:8000/api/v1', 'development'),
      isTrue,
    );
    expect(
        AppConfig.isAllowedApiBaseUrl('http://localhost:8000/api/v1'), isTrue);
    expect(
        AppConfig.isAllowedApiBaseUrl('http://127.0.0.1:8000/api/v1'), isTrue);
    expect(
        AppConfig.isAllowedApiBaseUrl('http://10.0.2.2:8000/api/v1'), isFalse);
    expect(AppConfig.isAllowedApiBaseUrl('http://api.example.test/api/v1'),
        isFalse);
    expect(
        AppConfig.isAllowedApiBaseUrlForVariant(
            'http://127.0.0.1:8000/api/v1', 'production'),
        isFalse);
    expect(
        AppConfig.isAllowedApiBaseUrlForVariant(
            'http://10.0.2.2:8000/api/v1', 'development'),
        isFalse);
    expect(
        AppConfig.isAllowedApiBaseUrlForVariant(
            'https://api.example.test/api/v1', 'staging'),
        isTrue);
    expect(AppConfig.isAllowedApiBaseUrlForVariant('', 'production'), isFalse);
  });

  test('diagnostic failure categories distinguish socket TLS timeout and JSON',
      () {
    expect(ApiClient.diagnosticFailureKind(TimeoutException('timed out')),
        'timeout');
    expect(
        ApiClient.diagnosticFailureKind(
            const HandshakeException('certificate rejected')),
        'tls');
    expect(
        ApiClient.diagnosticFailureKind(
            const SocketException('connection refused')),
        'socket');
    expect(ApiClient.diagnosticFailureKind(const FormatException()),
        'invalid_json');
  });

  test('timeout failures are classified as network failures', () {
    expect(ApiClient.isNetworkFailure(TimeoutException('timed out')), isTrue);
  });
}
