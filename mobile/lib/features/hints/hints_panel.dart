import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';

class HintsPanel extends StatefulWidget {
  const HintsPanel({required this.nodeId, super.key});

  final String nodeId;

  @override
  State<HintsPanel> createState() => _HintsPanelState();
}

class _HintsPanelState extends State<HintsPanel> {
  bool loading = false;
  String? hint;
  String? message;

  Future<void> consume() async {
    setState(() {
      loading = true;
      message = null;
    });
    try {
      final result = await ApiClient().post(
          '/runtime/nodes/${widget.nodeId}/hints/consume',
          device: true,
          idempotent: true,
          idempotencyKey: const Uuid().v4());
      if (mounted) setState(() => hint = result['content'] as String?);
    } on ApiError catch (exception) {
      if (mounted) setState(() => message = exception.userMessage);
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Card(
      child: Padding(
          padding: const EdgeInsets.all(16),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            const Text('راهنما'),
            if (hint != null)
              Padding(
                  padding: const EdgeInsets.only(top: 8), child: Text(hint!)),
            if (message != null)
              Padding(
                  padding: const EdgeInsets.only(top: 8),
                  child: Text(message!,
                      style: const TextStyle(color: Colors.red))),
            const SizedBox(height: 8),
            OutlinedButton(
                onPressed: loading ? null : consume,
                child: loading
                    ? const CircularProgressIndicator()
                    : const Text('دریافت راهنما'))
          ])));
}
