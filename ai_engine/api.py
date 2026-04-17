from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import joblib
import google.generativeai as genai
import re # 🛡️ إضافة مهمة عشان التنظيف

# 🛡️ دالة التنظيف (لازم تكون موجودة هنا عشان النص اللي جاي من الموبايل يتنظف قبل ما يتصنف)
def clean_arabic_text(text):
    text = str(text)
    text = re.sub(r'[^\u0600-\u06FF\s]', '', text)
    text = re.sub(r'[أإآ]', 'ا', text)
    text = re.sub(r'ة', 'ه', text)
    text = re.sub(r'ى', 'ي', text)
    return text

# 1. تحميل الموديل الجاهز (في أجزاء من الثانية)
model = joblib.load('shakawa_model.pkl')

app = FastAPI()

# 2. السماح للموبايل والـ PHP بالاتصال (CORS)
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"], 
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# 3. نموذج استلام البيانات
class Complaint(BaseModel):
    text: str
    customer_name: str = "يا غالي"

# 🛑 تنبيه: في بيئة العمل الحقيقية المفاتيح دي بتتحط في ملف .env
API_KEYS = [
    "",
    "",
    "",
    "",
    "",
]

@app.post("/chatbot")
async def shakawa_bot(item: Complaint):
    text = item.text.strip()
    customer_name = item.customer_name.strip()

    # المسار الأول: التوجه لصفحة المتابعة
    track_keywords = ["اتابع", "متابعة", "اراجع", "مراجعة", "تتبع", "رقم شكوتي"]
    if any(word in text for word in track_keywords):
        if any(char.isdigit() for char in text):
            comp_id = ''.join(filter(str.isdigit(), text))
            return {"reply": f"تمام يا {customer_name}، هحولك لصفحة المتابعة عشان نشوف حالة الشكوى رقم #{comp_id}..", "type": "go_to_track", "id": comp_id}
        
        return {"reply": f"تمام يا {customer_name}، هحولك دلوقتي لصفحة متابعة الشكاوى عشان تبحث برقم شكوتك.", "type": "go_to_track"}
    
    # المسار الثاني: التنظيف ثم التوقع بالذكاء الاصطناعي الخاص بينا
    cleaned_text = clean_arabic_text(text)
    prediction = model.predict([cleaned_text])[0]
    
    # المسار الثالث: التدخل بالـ Generative AI (جيمي)
    prompt = f"""
    أنت مساعد ذكي ومصري جدع اسمك "جيمي" في تطبيق "شكاوى" لشركات الاتصالات.
    اسم العميل الذي يتحدث معك الآن هو: "{customer_name}"
    العميل يقول: "{text}"
    (تصنيف النظام للمشكلة: {prediction}).
    
    تعليماتك:
    1. ابدأ ردك دائماً بالترحيب بالعميل ومناداته باسمه.
    2. إذا كانت مشكلة تقنية، حاول حلها معه بخطوات بسيطة أولاً.
    3. إذا كانت المشكلة معقدة ولا يمكن حلها بالنصائح، قل نصاً: "تمام، هحولك دلوقتي لصفحة تقديم الشكوى عشان الموظفين يتابعوها."
    4. إذا كان يدردش، بادله الدردشة بلهجة مصرية محترمة.
    اجعل الرد قصيراً ومفيداً.
    """  
    dynamic_reply = ""
    reply_type = "chat"

    # حركة صياعة: تدوير المفاتيح لتخطي الحظر
    for key in API_KEYS:
        try:
            genai.configure(api_key=key)
            llm = genai.GenerativeModel('gemini-2.5-flash')
            response = llm.generate_content(prompt)
            dynamic_reply = response.text.strip()
            
            if "هحولك" in dynamic_reply or "تقديم الشكوى" in dynamic_reply:
                reply_type = "go_to_form"
            else:
                reply_type = "chat"
            
            break # لو المفتاح اشتغل، اخرج من اللوب
            
        except Exception as e:
            print(f"❌ المفتاح ده فيه مشكلة: {e}")
            continue

    if not dynamic_reply:
        dynamic_reply = f"معلش يا {customer_name} السيرفر عليه ضغط. هحولك فوراً لصفحة تسجيل الشكوى عشان نلحق نحلها."
        reply_type = "go_to_form"

    return {
        "reply": dynamic_reply,
        "type": reply_type,
        "category": prediction, # بنرجع التصنيف للـ PHP عشان يخزنه
        "description": text
    }