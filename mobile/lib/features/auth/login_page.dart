import 'package:flutter/material.dart';

import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';

class LoginPage extends StatefulWidget {
  const LoginPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<LoginPage> createState() => _LoginPageState();
}

class _LoginPageState extends State<LoginPage> {
  final identifier = TextEditingController();
  final password = TextEditingController();
  bool sending = false;
  String? error;

  @override
  void dispose() {
    identifier.dispose();
    password.dispose();
    super.dispose();
  }

  Future<void> submit() async {
    if (sending) return;
    if (identifier.text.trim().isEmpty || password.text.isEmpty) {
      setState(() => error = 'شماره موبایل و رمز عبور را وارد کنید');
      return;
    }
    setState(() {
      sending = true;
      error = null;
    });
    try {
      await widget.auth.login(identifier.text.trim(), password.text);
    } on ApiError catch (exception) {
      if (mounted) setState(() => error = exception.userMessage);
    } catch (_) {
      if (mounted) setState(() => error = 'خطایی رخ داد. دوباره تلاش کنید.');
    } finally {
      if (mounted) setState(() => sending = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        body: SafeArea(
          child: Center(
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 440),
              child: Padding(
                padding: const EdgeInsets.all(24),
                child: SingleChildScrollView(
                    child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                      const Text('NEXO',
                          style: TextStyle(
                              fontSize: 38, fontWeight: FontWeight.bold)),
                      const SizedBox(height: 8),
                      const Text('ورود بازیکن'),
                      const SizedBox(height: 28),
                      TextField(
                          controller: identifier,
                          decoration: const InputDecoration(
                              labelText: 'ایمیل یا شماره همراه',
                              border: OutlineInputBorder())),
                      const SizedBox(height: 12),
                      TextField(
                          controller: password,
                          obscureText: true,
                          decoration: const InputDecoration(
                              labelText: 'رمز عبور',
                              border: OutlineInputBorder())),
                      if (error != null || widget.auth.error != null) ...[
                        const SizedBox(height: 12),
                        Text(error ?? widget.auth.error!.userMessage,
                            style: const TextStyle(color: Colors.red))
                      ],
                      const SizedBox(height: 20),
                      FilledButton(
                          onPressed: sending ? null : submit,
                          child: sending
                              ? const CircularProgressIndicator()
                              : const Text('ورود')),
                    ])),
              ),
            ),
          ),
        ),
      );
}
