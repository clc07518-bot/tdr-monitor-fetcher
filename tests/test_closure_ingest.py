import ast
import json
import pathlib
import unittest
from unittest.mock import Mock
import requests

ROOT = pathlib.Path(__file__).resolve().parents[1]
tree = ast.parse((ROOT / 'fetcher.py').read_text())
names = {'ingest_closures', 'failed_sources', 'deduplicate_items'}
functions = ast.Module(body=[n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name in names], type_ignores=[])
ns = {'Any': object, 'DRY_RUN': False, 'WP_BASE': 'https://example.test', 'TOKEN': 'test-only', 'requests': requests}
exec(compile(functions, '<closure-functions>', 'exec'), ns)

class ClosureTests(unittest.TestCase):
    def setUp(self):
        self.items = json.loads((ROOT / 'tests/official-closures.json').read_text())['tds']
        self.items = [dict(x, id=str(i)) for i,x in enumerate(self.items)]
        self.original = requests.post
        requests.post = Mock(return_value=Mock(status_code=200, json=lambda: {'ok': True, 'count': len(self.items)}))
    def tearDown(self):
        requests.post = self.original
    def test_complete_closures_not_truncated(self):
        self.assertEqual(len(ns['deduplicate_items']('stop_tds', self.items)),48)
        self.assertEqual(len(ns['deduplicate_items']('update', self.items)),30)
    def test_duplicates_removed(self):
        self.assertEqual(len(ns['deduplicate_items']('stop_tds', self.items+self.items)),48)
    def test_snapshot_success(self):
        self.assertTrue(ns['ingest_closures']('stop_tds', self.items)['ok'])
        self.assertEqual(requests.post.call_args.kwargs['json']['items'],self.items)
    def test_bad_token_fails_even_when_news_succeeds(self):
        requests.post.return_value=Mock(status_code=401,json=lambda:{'code':'bad_token'})
        result=ns['ingest_closures']('stop_tds',self.items)
        self.assertEqual(ns['failed_sources']({'update':{'status':200},'closures_tds':result}),['closures_tds'])
    def test_count_mismatch_fails(self):
        requests.post.return_value=Mock(status_code=200,json=lambda:{'ok':True,'count':47})
        self.assertIn('error',ns['ingest_closures']('stop_tds',self.items))
    def test_empty_does_not_send(self):
        self.assertIn('error',ns['ingest_closures']('stop_tds',[]))
        requests.post.assert_not_called()
    def test_timeout_is_not_resent(self):
        requests.post.side_effect=requests.exceptions.ReadTimeout()
        self.assertIn('error',ns['ingest_closures']('stop_tds',self.items))
        self.assertEqual(requests.post.call_count,1)
    def test_bad_json_and_http_fail(self):
        requests.post.return_value=Mock(status_code=500,json=lambda:['unexpected'])
        self.assertIn('error',ns['ingest_closures']('stop_tds',self.items))
        self.assertEqual(ns['failed_sources']({'stop_tds':{'status':401,'code':'bad_token'}}),['stop_tds'])

if __name__=='__main__': unittest.main()
