# ml/scripts/3_predict.py
import sys
import json
import joblib
import pandas as pd
from config import MODEL_PATH

def predict_approval(data):
    """
    Predict if booking should be auto-approved
    
    Args:
        data (dict): Booking features
    
    Returns:
        dict: Prediction result with confidence
    """
    try:
        # Load model
        model = joblib.load(f"{MODEL_PATH}decision_tree.pkl")
        
        # Prepare features
        features = pd.DataFrame([{
            'hour_of_day': data.get('hour_of_day', 10),
            'day_of_week': data.get('day_of_week', 1),
            'advance_booking_days': data.get('advance_booking_days', 7),
            'duration_hours': data.get('duration_hours', 2),
            'user_approval_rate': data.get('user_approval_rate', 0.5),
            'user_completion_rate': data.get('user_completion_rate', 0.5),
            'same_day_facility_demand': data.get('same_day_facility_demand', 0),
            'is_weekend': data.get('is_weekend', 0)
        }])
        
        # Predict
        prediction = model.predict(features)[0]
        confidence = model.predict_proba(features)[0][prediction]
        
        result = {
            'prediction': 'approve' if prediction == 1 else 'manual_review',
            'confidence': float(confidence),
            'should_auto_approve': prediction == 1 and confidence >= 0.7
        }
        
        return result
        
    except Exception as e:
        return {'error': str(e)}

def predict_noshow(data):
    """
    Predict probability of no-show
    
    Args:
        data (dict): Booking features
    
    Returns:
        dict: No-show probability and recommendation
    """
    try:
        # Load model
        model = joblib.load(f"{MODEL_PATH}logistic_regression.pkl")
        
        # Prepare features
        features = pd.DataFrame([{
            'hour_of_day': data.get('hour_of_day', 10),
            'day_of_week': data.get('day_of_week', 1),
            'advance_booking_days': data.get('advance_booking_days', 7),
            'duration_hours': data.get('duration_hours', 2),
            'user_completion_rate': data.get('user_completion_rate', 0.5),
            'is_weekend': data.get('is_weekend', 0),
            'same_day_facility_demand': data.get('same_day_facility_demand', 0)
        }])
        
        # Predict probability of showing up
        show_probability = model.predict_proba(features)[0][1]
        noshow_probability = 1 - show_probability
        
        result = {
            'noshow_probability': float(noshow_probability),
            'show_probability': float(show_probability),
            'send_extra_reminder': noshow_probability > 0.6,
            'risk_level': 'high' if noshow_probability > 0.7 else 'medium' if noshow_probability > 0.4 else 'low'
        }
        
        return result
        
    except Exception as e:
        return {'error': str(e)}

if __name__ == "__main__":
    # Accept JSON input from command line
    if len(sys.argv) < 3:
        print(json.dumps({'error': 'Usage: python 3_predict.py <model_type> <json_data>'}))
        sys.exit(1)
    
    model_type = sys.argv[1]
    input_data = json.loads(sys.argv[2])
    
    if model_type == 'approval':
        result = predict_approval(input_data)
    elif model_type == 'noshow':
        result = predict_noshow(input_data)
    else:
        result = {'error': 'Invalid model type'}
    
    print(json.dumps(result))