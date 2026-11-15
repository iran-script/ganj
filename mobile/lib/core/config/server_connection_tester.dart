import 'dart:async';
import 'dart:io';

import 'package:http/http.dart' as http;

import 'app_config.dart';

class ServerConnectionTester {
  ServerConnectionTester({http.Client? client}) : _client = client;

  final http.Client? _client;

  Future<void> test(String value) async {
    final normalized = AppConfig.normalizeApiBaseUrl(value);
    if (normalized == null || !AppConfig.isAllowedApiBaseUrl(normalized)) {
      throw const FormatException('Invalid API base URL.');
    }
    final baseUri = Uri.parse(normalized);
    final healthUri = baseUri.replace(path: '${baseUri.path}/health');
    final client = _client ?? http.Client();
    try {
      final response = await client
          .get(healthUri, headers: const {'Accept': 'application/json'})
          .timeout(const Duration(seconds: 8));
      if (response.statusCode == 401 ||
          (response.statusCode >= 200 && response.statusCode < 300)) {
        return;
      }
      throw ServerConnectionException(
          kind: ServerConnectionFailure.http, statusCode: response.statusCode);
    } on TimeoutException {
      throw const ServerConnectionException(
          kind: ServerConnectionFailure.timeout);
    } on SocketException {
      throw const ServerConnectionException(
          kind: ServerConnectionFailure.network);
    } on http.ClientException {
      throw const ServerConnectionException(
          kind: ServerConnectionFailure.network);
    } finally {
      if (_client == null) client.close();
    }
  }
}

enum ServerConnectionFailure { network, timeout, http }

class ServerConnectionException implements Exception {
  const ServerConnectionException({required this.kind, this.statusCode});

  final ServerConnectionFailure kind;
  final int? statusCode;
}
