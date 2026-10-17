import 'package:flutter/material.dart';
import 'package:uuid/uuid.dart';

import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/errors/api_error.dart';

class HintsPanel extends StatefulWidget {
  const HintsPanel({
    required this.nodeId,
    this.enabled = true,
    this.api,
    this.createIdempotencyKey,
    super.key,
  });

  final String nodeId;
  final bool enabled;
  final ApiClient? api;
  final String Function()? createIdempotencyKey;

  @override
  State<HintsPanel> createState() => _HintsPanelState();
}

class _HintsPanelState extends State<HintsPanel> {
  bool loading = false;
  String? hint;
  String? message;
  String? pendingKey;
  late final ApiClient api = widget.api ?? ApiClient();

  @override
  void didUpdateWidget(covariant HintsPanel oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.nodeId != widget.nodeId) {
      hint = null;
      message = null;
      pendingKey = null;
    }
  }

  Future<void> consume() async {
    if (loading || !widget.enabled) return;
    pendingKey ??= widget.createIdempotencyKey?.call() ?? const Uuid().v4();
    setState(() {
      loading = true;
      message = null;
    });
    try {
      final result = await api.post(
          '/runtime/nodes/${widget.nodeId}/hints/consume',
          device: true,
          idempotent: true,
          idempotencyKey: pendingKey);
      if (mounted) {
        setState(() {
          hint = result['content'] as String?;
          pendingKey = null;
        });
      }
    } catch (exception) {
      if (mounted) {
        setState(() {
          message = ApiClient.isNetworkFailure(exception)
              ? 'نتیجه دریافت راهنما نامشخص است. برای بررسی دوباره تلاش کنید.'
              : exception is ApiError
                  ? exception.userMessage
                  : 'دریافت راهنما انجام نشد؛ دوباره تلاش کنید.';
          if (!ApiClient.isNetworkFailure(exception)) pendingKey = null;
        });
      }
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
            if (message != null) ...[
              NexoNotice(message!,
                  icon: Icons.error_outline, color: NexoTheme.danger),
            ],
            const SizedBox(height: 8),
            OutlinedButton(
                onPressed: loading || !widget.enabled ? null : consume,
                child: loading
                    ? const SizedBox.square(
                        dimension: 20,
                        child: CircularProgressIndicator(strokeWidth: 2))
                    : Text(
                        pendingKey == null ? 'دریافت راهنما' : 'بررسی دوباره'))
          ])));
}
