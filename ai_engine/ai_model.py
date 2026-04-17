import pandas as pd
import re
from sklearn.feature_extraction.text import CountVectorizer
from sklearn.naive_bayes import MultinomialNB
from sklearn.pipeline import make_pipeline
import arabic_reshaper
from bidi.algorithm import get_display
import joblib

# دالة لتعديل شكل العربي في شاشة الويندوز
def print_ar(text):
    reshaped_text = arabic_reshaper.reshape(text)
    bidi_text = get_display(reshaped_text)
    print(bidi_text)

# 🛡️ الإضافة الذهبية: دالة لتنظيف النص العربي (Text Cleaning)
def clean_arabic_text(text):
    text = str(text)
    # إزالة التشكيل والرموز الغريبة والأرقام (نخلي الحروف بس)
    text = re.sub(r'[^\u0600-\u06FF\s]', '', text)
    # توحيد أشكال الألف (أ، إ، آ -> ا) والياء والتاء المربوطة
    text = re.sub(r'[أإآ]', 'ا', text)
    text = re.sub(r'ة', 'ه', text)
    text = re.sub(r'ى', 'ي', text)
    return text

print_ar("⏳ جاري قراءة البيانات وتدريب الذكاء الاصطناعي...")

# 1. قراءة البيانات
df = pd.read_csv('complaints_data.csv')

# 2. تنظيف البيانات قبل التدريب (هنا الاحترافية)
df['cleaned_text'] = df['text'].apply(clean_arabic_text)

# 3. بناء خط الأنابيب (Pipeline) وتدريب الموديل
# ضفنا ngram_range عشان الموديل يفهم الكلمات المركبة (زي: "النت قاطع" بدل "النت" لوحدها)
model = make_pipeline(CountVectorizer(ngram_range=(1, 2)), MultinomialNB())
model.fit(df['cleaned_text'], df['category'])

print_ar("✅ التدريب خلص! الموديل جاهز للاختبار.\n")

# 4. حفظ "مخ" الذكاء الاصطناعي
joblib.dump(model, 'shakawa_model.pkl')
print_ar("📁 تم حفظ النموذج بنجاح في ملف shakawa_model.pkl")

# ==========================================
# 5. اختبار سريع
new_complaint = "الراوتر عندي فاصل واللمبة مش بتنور بقالها ساعة"
cleaned_complaint = clean_arabic_text(new_complaint) # لازم ننظف النص الجديد برضه
prediction = model.predict([cleaned_complaint])

print_ar("📝 الشكوى الجديدة: " + new_complaint)
print_ar("🎯 التصنيف الذكي: " + prediction[0])