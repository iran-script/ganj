import 'package:flutter_test/flutter_test.dart';
import 'package:nexo_player/features/runtime/node_renderer.dart';

void main() {
  testWidgets('unknown and AR nodes fail closed without crashing', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: NodeRenderer(node: {'node_type': 'AR Challenge', 'config': {}}, sessionId: 'session', onSubmit: _noop)));
    expect(find.text('قابلیت AR در این نسخه فعال نیست.'), findsOneWidget);
    await tester.pumpWidget(const MaterialApp(home: NodeRenderer(node: {'node_type': 'Future Node', 'config': {}}, sessionId: 'session', onSubmit: _noop)));
    expect(find.text('محتوای این مرحله در نسخه فعلی پشتیبانی نمی‌شود.'), findsOneWidget);
  });
}

Future<void> _noop(Map<String, dynamic> _) async {}
