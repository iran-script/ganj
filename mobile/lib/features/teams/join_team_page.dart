import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../app/nexo_theme.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'team_repository.dart';

class JoinTeamPage extends StatefulWidget {
  const JoinTeamPage({required this.auth, super.key});
  final AuthController auth;

  @override
  State<JoinTeamPage> createState() => _JoinTeamPageState();
}

class _JoinTeamPageState extends State<JoinTeamPage> {
  final formKey = GlobalKey<FormState>();
  final inviteCode = TextEditingController();
  late final TeamRepository repository = TeamRepository(widget.auth.api);
  bool submitting = false;
  String? error;

  @override
  void dispose() {
    inviteCode.dispose();
    super.dispose();
  }

  Future<void> join() async {
    if (submitting || !formKey.currentState!.validate()) return;
    setState(() { submitting = true; error = null; });
    try {
      final team = await repository.join(inviteCode.text);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('به تیم «${team.name}» پیوستید.')));
        Navigator.of(context).pop(true);
      }
    } on ApiError catch (failure) {
      if (mounted) setState(() => error = failure.userMessage);
    } catch (failure) {
      if (mounted) setState(() => error = ApiClient.isNetworkFailure(failure) ? 'اتصال برقرار نشد؛ پس از اتصال دوباره تلاش کنید.' : 'پیوستن به تیم انجام نشد.');
    } finally {
      if (mounted) setState(() => submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('ورود به تیم')),
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 560),
              child: ListView(padding: const EdgeInsets.all(NexoSpace.x4), children: [
                const NexoCityHeader(eyebrow: 'هم‌تیمی‌ها منتظرند', title: 'با کد دعوت وارد شوید', body: 'کد را از Captain تیم دریافت کنید.'),
                Form(key: formKey, child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  TextFormField(
                    controller: inviteCode,
                    textDirection: TextDirection.ltr,
                    textInputAction: TextInputAction.done,
                    onFieldSubmitted: (_) => join(),
                    decoration: const InputDecoration(labelText: 'کد دعوت', prefixIcon: Icon(Icons.key_outlined)),
                    validator: (value) => value == null || value.trim().isEmpty ? 'کد دعوت را وارد کنید.' : null,
                  ),
                  if (error != null) ...[
                    const SizedBox(height: 12),
                    NexoNotice(error!, icon: Icons.error_outline, color: NexoTheme.danger),
                  ],
                  const SizedBox(height: 18),
                  FilledButton.icon(
                    onPressed: submitting ? null : join,
                    icon: submitting ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2, color: NexoTheme.background)) : const Icon(Icons.group_add_outlined),
                    label: Text(submitting ? 'در حال بررسی کد…' : 'پیوستن به تیم'),
                  ),
                ])),
              ]),
            ),
          ),
        ),
      );
}
