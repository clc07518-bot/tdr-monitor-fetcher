import ast, copy, json, pathlib, time, unittest
p=pathlib.Path(__file__).parent
module=ast.parse((p.parent/'fetcher.py').read_text())
fn=next(n for n in module.body if isinstance(n,ast.FunctionDef) and n.name=='parse_ticket_sale_snapshot')
ns={};exec(compile(ast.Module(body=[fn],type_ignores=[]),'ticket_parser','exec'),ns);parse=ns['parse_ticket_sale_snapshot']
fixture=json.loads('{\n  "tdl": {\n    "date": 20261023,\n    "openTime": {\n      "open": "9:00",\n      "close": "21:00"\n    },\n    "day": "2026年10月23日 (金)",\n    "highlightFlg": "0",\n    "info": {\n      "G1": {\n        "name": "1日券",\n        "list": {\n          "T1": {\n            "name": "1デーパスポート",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥10,900\u3000中人：￥9,000\u3000小人：￥5,600"\n          },\n          "T2": {\n            "name": "1デーパスポート（障がいのある方向け）",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion is-few"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥8,700\u3000中人：￥7,200\u3000小人：￥4,400"\n          }\n        }\n      },\n      "G2": {\n        "name": "入園時間指定券",\n        "list": {\n          "T7": {\n            "name": "アフター5パスポート（平日限定）",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥6,600\u3000中人：￥5,700\u3000小人：￥3,800"\n          }\n        }\n      }\n    }\n  },\n  "tds": {\n    "date": 20261023,\n    "openTime": {\n      "open": "9:00",\n      "close": "21:00"\n    },\n    "day": "2026年10月23日 (金)",\n    "highlightFlg": "0",\n    "info": {\n      "G1": {\n        "name": "1日券",\n        "list": {\n          "T1": {\n            "name": "1デーパスポート",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥10,900\u3000中人：￥9,000\u3000小人：￥5,600"\n          },\n          "T2": {\n            "name": "1デーパスポート（障がいのある方向け）",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion is-few"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥8,700\u3000中人：￥7,200\u3000小人：￥4,400"\n          }\n        }\n      },\n      "G2": {\n        "name": "入園時間指定券",\n        "list": {\n          "T7": {\n            "name": "アフター5パスポート（平日限定）",\n            "ticket": {\n              "passFlg": false,\n              "classPopup": "conversion"\n            },\n            "url": "https://plan.tokyodisneyresort.jp/2/4/?lang=ja",\n            "ageText": "大人：￥6,600\u3000中人：￥5,700\u3000小人：￥3,800"\n          }\n        }\n      }\n    }\n  }\n}')
def html(data):return '<script>var ticketPopup = '+json.dumps(data,ensure_ascii=False)+';</script>'
class SalesTests(unittest.TestCase):
 def test_actual_both_parks_and_new_after5(self):
  for park in ('tdl','tds'):
   result=parse(html({'20261023':fixture}),park,'20261023',1792721400)
   self.assertEqual(len(result['items']),3)
   self.assertEqual(result['items'][-1]['name'],'アフター5パスポート（平日限定）')
   self.assertEqual(result['items'][-1]['prices'],{'adult':6600,'junior':5700,'child':3800})
 def test_limited_unknown_name_is_included_but_soldout_excluded(self):
  data=copy.deepcopy(fixture); tickets=data['tdl']['info']['G1']['list']
  limited=copy.deepcopy(next(iter(tickets.values())));limited['name']='未来の期間限定パスポート';limited['ticket']['classPopup']='conversion is-few';tickets['LIMITED']=limited
  sold=copy.deepcopy(limited);sold['name']='価格あり売切れ';sold['ticket']['classPopup']='conversion is-soldout';tickets['SOLD']=sold
  unavailable=copy.deepcopy(limited);unavailable['name']='販売なし';unavailable['ticket']['classPopup']='is-none';tickets['NONE']=unavailable
  result=parse(html({'20261023':data}),'tdl','20261023')
  names=[x['name'] for x in result['items']]
  self.assertIn('未来の期間限定パスポート',names);self.assertNotIn('価格あり売切れ',names);self.assertNotIn('販売なし',names)
  self.assertEqual(next(x for x in result['items'] if x['name']=='未来の期間限定パスポート')['sale_status'],'残りわずか')
 def test_wrong_day_missing_data_are_errors_not_soldout(self):
  for text,date,park in [(html({'20261024':fixture}),'20261023','tdl'),('<html>connection failure</html>','20261023','tdl'),(html({'20261023':{}}),'20261023','tds')]:
   with self.assertRaises(ValueError):parse(text,park,date)
 def test_all_soldout_is_valid_empty_sale_list(self):
  data=copy.deepcopy(fixture)
  for group in data['tdl']['info'].values():
   for ticket in group['list'].values():ticket['ticket']['classPopup']='conversion is-soldout'
  self.assertEqual(parse(html({'20261023':data}),'tdl','20261023')['items'],[])
if __name__=='__main__':unittest.main()
