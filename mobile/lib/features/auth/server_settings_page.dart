import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/config/app_config.dart';
import '../../core/config/server_connection_tester.dart';

class ServerSettingsPage extends StatefulWidget {
  const ServerSettingsPage({
    required this.auth,
    this.onSaved,
    this.connectionTester,
    super.key,
  });

  final AuthController auth;
  final Future<void> Function()? onSaved;
  final ServerConnectionTester? connectionTester;

  @override
  State<ServerSettingsPage> createState() => _ServerSettingsPageState();
}

class _ServerSettingsPageState extends State<ServerSettingsPage> {
  late final TextEditingController address = TextEditingController(
      text: AppConfig.hasConfiguredApiBaseUrl ? AppConfig.apiBaseUrl : '');
  late final ServerConnectionTester tester =
      widget.connectionTester ?? ServerConnectionTester();
  bool checking = false;
  bool saving = false;
  bool connectionVerified = false;
  String? message;
  bool success = false;

  @override
  void dispose() {
    address.dispose();
    super.dispose();
  }

  Future<void> checkConnection() async {
    final normalized = AppConfig.normalizeApiBaseUrl(address.text);
    if (normalized == null || !AppConfig.isAllowedApiBaseUrl(normalized)) {
      _showMessage(_invalidAddressMessage, false);
      return;
    }
    setState(() {
      checking = true;
      connectionVerified = false;
      message = null;
    });
    try {
      await tester.test(normalized);
      if (mounted) {
        setState(() {
          address.text = normalized;
          connectionVerified = true;
        });
        _showMessage('اتصال به سرور برقرار است.', true);
      }
    } on ServerConnectionException catch (error) {
      _showMessage(_connectionMessage(error), false);
    } on FormatException {
      _showMessage(_invalidAddressMessage, false);
    } finally {
      if (mounted) setState(() => checking = false);
    }
  }

  Future<void> save() async {
    if (saving) return;
    final normalized = AppConfig.normalizeApiBaseUrl(address.text);
    if (normalized == null || !AppConfig.isAllowedApiBaseUrl(normalized)) {
      _showMessage(_invalidAddressMessage, false);
      return;
    }
    if (!connectionVerified || normalized != address.text) {
      await checkConnection();
      if (!mounted || !connectionVerified) return;
    }
    setState(() {
      saving = true;
      message = null;
    });
    try {
      final changed = await AppConfig.saveApiBaseUrl(
          widget.auth.api.store, normalized);
      if (changed) await widget.auth.resetForServerChange();
      if (mounted) await widget.onSaved?.call();
    } on FormatException {
      _showMessage(_invalidAddressMessage, false);
    } catch (_) {
      _showMessage('ذخیره آدرس سرور انجام نشد. دوباره تلاش کنید.', false);
    } finally {
      if (mounted) setState(() => saving = false);
    }
  }

  void _showMessage(String value, bool isSuccess) {
    if (!mounted) return;
    setState(() {
      message = value;
      success = isSuccess;
    });
  }

  String _connectionMessage(ServerConnectionException error) => switch (error.kind) {
        ServerConnectionFailure.timeout =>
          'پاسخی از سرور دریافت نشد. آدرس و روشن‌بودن سرور را بررسی کنید.',
        ServerConnectionFailure.network =>
          'اتصال شبکه یا DNS برقرار نشد. IP، Wi‑Fi و دسترسی شبکه را بررسی کنید.',
        ServerConnectionFailure.http =>
          error.statusCode == 404
              ? 'مسیر API پیدا نشد؛ آدرس را بدون /api یا با /api/v1 وارد کنید.'
              : 'سرور پاسخ خطا داد (${error.statusCode}).',
      };

  static const _invalidAddressMessage =
      'آدرس معتبر نیست. نمونه: http://192.168.1.100:8000';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('تنظیمات سرور')),
        body: NexoPageBody(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(NexoSpace.x6),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                const NexoBrandMark(),
                const SizedBox(height: NexoSpace.x6),
                Text('اتصال به Backend',
                    style: Theme.of(context).textTheme.headlineSmall),
                const SizedBox(height: NexoSpace.x2),
                const Text(
                    'آدرس IP یا دامنهٔ سرور را وارد کنید. مسیر /api/v1 در صورت نیاز خودکار اضافه می‌شود.'),
                const SizedBox(height: NexoSpace.x6),
                TextField(
                  controller: address,
                  keyboardType: TextInputType.url,
                  textDirection: TextDirection.ltr,
                  onChanged: (_) => setState(() => connectionVerified = false),
                  decoration: const InputDecoration(
                    labelText: 'آدرس سرور',
                    hintText: 'http://192.168.1.100:8000',
                    prefixIcon: Icon(Icons.dns_outlined),
                  ),
                ),
                const SizedBox(height: NexoSpace.x2),
                const Text('نمونه: http://10.0.2.2:8000 یا https://api.example.com',
                    textDirection: TextDirection.ltr,
                    style: TextStyle(color: NexoTheme.muted, fontSize: 12)),
                if (message != null) ...[
                  const SizedBox(height: NexoSpace.x4),
                  NexoNotice(message!,
                      icon: success ? Icons.check_circle_outline : Icons.warning_amber_rounded,
                      color: success ? NexoTheme.success : NexoTheme.danger),
                ],
                const SizedBox(height: NexoSpace.x6),
                OutlinedButton.icon(
                  onPressed: checking || saving ? null : checkConnection,
                  icon: checking
                      ? const SizedBox.square(
                          dimension: 18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.wifi_find),
                  label: Text(checking ? 'در حال بررسی…' : 'تست اتصال'),
                ),
                const SizedBox(height: NexoSpace.x3),
                FilledButton.icon(
                  onPressed: checking || saving ? null : save,
                  icon: saving
                      ? const SizedBox.square(
                          dimension: 18,
                          child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.save_outlined),
                  label: Text(saving ? 'در حال ذخیره…' : 'ذخیره آدرس سرور'),
                ),
              ],
            ),
          ),
        ),
      );
}
