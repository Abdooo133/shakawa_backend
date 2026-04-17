import pandas as pd
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import make_pipeline
import mysql.connector

print("⏳ جاري تدريب الموديل...")
# 1. تدريب الموديل بنفس ملفك
df = pd.read_csv('complaints_data.csv')
model = make_pipeline(CountVectorizer(), MultinomialNB())
model.fit(df['text'], df['category'])

print("🔌 جاري الاتصال بقاعدة البيانات...")
# 2. الاتصال بقاعدة بيانات XAMPP
db = mysql.connector.connect(
    host="localhost",
    user="root",
    password="",
    database="shakawa_backend" # اتأكد إن ده اسم الداتا بيز بتاعك
)
cursor = db.cursor(dictionary=True)

# 3. هنجيب كل الشكاوى القديمة اللي لسه متصنفتش
cursor.execute("SELECT id, description FROM complaints WHERE ai_category IS NULL OR ai_category = 'غير مصنف (قديم)' OR ai_category = ''")
records = cursor.fetchall()

print(f"🎯 تم العثور على {len(records)} شكوى تحتاج للتصنيف. جاري العمل...")

# 4. الذكاء الاصطناعي بيقرأ ويصنف ويحدث الداتا بيز
count = 0
for row in records:
    # لو وصف الشكوى فاضي أو فيه حروف غريبة نتخطاه
    text = str(row['description'])
    if len(text.strip()) > 2:
        prediction = model.predict([text])[0]
        # تحديث الشكوى في الداتا بيز
        cursor.execute("UPDATE complaints SET ai_category = %s WHERE id = %s", (prediction, row['id']))
        count += 1

db.commit() # حفظ التعديلات
print(f"✅ تمت المهمة بنجاح! تم تصنيف {count} شكوى بالذكاء الاصطناعي.")