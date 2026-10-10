import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';

class QrScannerPage extends StatefulWidget {
  const QrScannerPage({required this.sessionId, required this.onValue, super.key});

  final String sessionId;
  final Future<void> Function(String value) onValue;

  @override
  State<QrScannerPage> createState() => _QrScannerPageState();
}

class _QrScannerPageState extends State<QrScannerPage> {
  bool handled = false;
  String? error;

  Future<void> found(String? value) async {
    if (handled || value == null || value.isEmpty) return;
    setState(() => handled = true);
    try {
      final result = await ApiClient().post('/qr/scan', device: true, idempotent: true, body: {'session_id': widget.sessionId, 'qr_identifier': value});
      await widget.onValue(result['one_time_token'] as String? ?? value);
      if (mounted) Navigator.pop(context);
    } on ApiError catch (exception) {
      if (mounted) setState(() { error = exception.userMessage; handled = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const Text('اسکن QR')), body: Stack(children: [MobileScanner(onDetect: (capture) => found(capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue)), if (error != null) Align(alignment: Alignment.bottomCenter, child: Container(color: Colors.black87, padding: const EdgeInsets.all(16), child: Text(error!, style: const TextStyle(color: Colors.white))))]));
}
