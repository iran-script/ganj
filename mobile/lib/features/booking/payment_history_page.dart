import 'package:flutter/material.dart';

import '../../app/nexo_components.dart';
import '../../core/api/api_client.dart';
import '../../core/auth/auth_controller.dart';
import '../../core/errors/api_error.dart';
import 'booking_repository.dart';

class PaymentHistoryPage extends StatefulWidget {
  const PaymentHistoryPage({required this.auth, super.key});

  final AuthController auth;

  @override
  State<PaymentHistoryPage> createState() => _PaymentHistoryPageState();
}

class _PaymentHistoryPageState extends State<PaymentHistoryPage> {
  late final repository = BookingRepository(widget.auth.api);
  late Future<List<Map<String, dynamic>>> payments = repository.payments();

  Future<void> _retry() async {
    setState(() => payments = repository.payments());
    try {
      await payments;
    } catch (_) {}
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('پرداخت‌های من')),
        body: SafeArea(
          child: FutureBuilder<List<Map<String, dynamic>>>(
            future: payments,
            builder: (context, snapshot) {
              if (!snapshot.hasData &&
                  snapshot.connectionState != ConnectionState.done) {
                return const NexoLoading(message: 'در حال دریافت پرداخت‌ها...');
              }
              if (snapshot.hasError || !snapshot.hasData) {
                return NexoErrorView(
                  message: snapshot.error is ApiError
                      ? (snapshot.error! as ApiError).userMessage
                      : 'پرداخت‌ها دریافت نشدند.',
                  offline: snapshot.error != null &&
                      ApiClient.isNetworkFailure(snapshot.error!),
                  onRetry: _retry,
                );
              }
              final values = snapshot.data!;
              if (values.isEmpty) {
                return const NexoEmptyView(
                  title: 'پرداختی ثبت نشده',
                  message:
                      'سفارش‌های پرداخت بازی‌ها پس از ثبت در این بخش نمایش داده می‌شوند.',
                  icon: Icons.receipt_long_outlined,
                );
              }
              return RefreshIndicator(
                onRefresh: _retry,
                child: ListView.separated(
                  physics: const AlwaysScrollableScrollPhysics(),
                  padding: const EdgeInsets.all(16),
                  itemCount: values.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 8),
                  itemBuilder: (context, index) {
                    final item = values[index];
                    final game =
                        item['game'] as Map<String, dynamic>? ?? const {};
                    final amount = (item['amount'] as num?)?.toInt() ?? 0;
                    return Card(
                        child: ListTile(
                      leading: const Icon(Icons.receipt_outlined),
                      title: Text(game['name'] as String? ?? 'بازی'),
                      subtitle: Text(
                          '$amount ${item['currency'] ?? ''} · ${_status(item['status'] as String?)}'),
                      trailing: const Icon(Icons.chevron_left),
                    ));
                  },
                ),
              );
            },
          ),
        ),
      );

  String _status(String? status) => switch (status) {
        'PENDING' => 'در انتظار پرداخت',
        'PROCESSING' => 'در حال بررسی',
        'PAID' => 'پرداخت‌شده',
        'FAILED' => 'ناموفق',
        'EXPIRED' => 'منقضی‌شده',
        'CANCELLED' => 'لغوشده',
        'REFUND_PENDING' => 'در انتظار بازپرداخت',
        'REFUNDED' => 'بازپرداخت‌شده',
        _ => 'وضعیت نامشخص',
      };
}
