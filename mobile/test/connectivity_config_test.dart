import 'dart:async';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/core/api/api_client.dart';
import 'package:nexo_player/core/config/app_config.dart';

void main() {
  test(
      'default debug URL is the test API and diagnostics expose no credentials',
      () {
    expect(AppConfig.apiBaseUrl, 'http://2.188.205.35:18100/api/v1');
    expect(AppConfig.diagnosticApiBaseUrl, 'http://2.188.205.35:18100/api/v1');
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

  test('only HTTPS and the exact test endpoint are allowed over HTTP', () {
    expect(AppConfig.isAllowedApiBaseUrl('https://api.example.test/api/v1'),
        isTrue);
    expect(
      AppConfig.isAllowedApiBaseUrl('http://2.188.205.35:18100/api/v1'),
      AppConfig.apiBuildVariant == 'test',
    );
    expect(
        AppConfig.isAllowedApiBaseUrl('http://localhost:8000/api/v1'), isFalse);
    expect(
        AppConfig.isAllowedApiBaseUrl('http://127.0.0.1:8000/api/v1'), isFalse);
    expect(
        AppConfig.isAllowedApiBaseUrl('http://10.0.2.2:8000/api/v1'), isFalse);
    expect(AppConfig.isAllowedApiBaseUrl('http://2.188.205.35:80/api/v1'),
        isFalse);
    expect(AppConfig.isAllowedApiBaseUrl('http://2.188.205.35:18100/other'),
        isFalse);
    expect(AppConfig.isAllowedApiBaseUrl('http://api.example.test/api/v1'),
        isFalse);
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
