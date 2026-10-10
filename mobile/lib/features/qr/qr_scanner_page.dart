import 'package:flutter/material.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:uuid/uuid.dart';

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
  String? pendingValue;
  String? pendingRequestId;

  Future<void> found(String? value) async {
    if (handled || value == null || value.isEmpty) return;
    setState(() => handled = true);
    if (pendingValue != value) {
      pendingValue = value;
      pendingRequestId = const Uuid().v4();
    }
    try {
      final result = await ApiClient().post('/qr/scan', device: true, idempotent: true, idempotencyKey: pendingRequestId,
          body: {'session_id': widget.sessionId, 'qr_identifier': value});
      await widget.onValue(result['one_time_token'] as String? ?? value);
      pendingValue = null;
      pendingRequestId = null;
      if (mounted) Navigator.pop(context);
    } on ApiError catch (exception) {
      if (exception.code != 'NETWORK_UNAVAILABLE') {
        pendingRequestId = null;
      }
      if (mounted) setState(() { error = exception.userMessage; handled = false; });
    } catch (_) {
      if (mounted) setState(() { error = 'برای تأیید QR اتصال اینترنت لازم است.'; handled = false; });
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(appBar: AppBar(title: const Text('اسکن QR')), body: Stack(children: [MobileScanner(onDetect: (capture) => found(capture.barcodes.isEmpty ? null : capture.barcodes.first.rawValue)), if (error != null) Align(alignment: Alignment.bottomCenter, child: Container(color: Colors.black87, padding: const EdgeInsets.all(16), child: Text(error!, style: const TextStyle(color: Colors.white))))]));
}
