import 'package:flutter/material.dart';
import 'package:flutter/foundation.dart';

import 'app/app.dart';
import 'core/api/api_client.dart';
import 'core/auth/auth_controller.dart';
import 'core/config/app_config.dart';
import 'core/storage/secure_store.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final store = SecureStore();
  final serverConfigured = await AppConfig.load(store);
  if (kDebugMode) {
    debugPrint('NEXO API_BASE_URL=${AppConfig.diagnosticApiBaseUrl}');
  }
  runApp(NexoApp(
    authController: AuthController(ApiClient(store: store)),
    serverConfigured: serverConfigured,
  ));
}
