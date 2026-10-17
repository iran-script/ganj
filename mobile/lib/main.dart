import 'package:flutter/material.dart';

import 'app/app.dart';
import 'core/config/app_config.dart';
import 'package:flutter/foundation.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  if (kDebugMode) {
    debugPrint('NEXO API_BASE_URL=${AppConfig.diagnosticApiBaseUrl}');
  }
  runApp(const NexoApp());
}
